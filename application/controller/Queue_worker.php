<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Worker da fila. Deve ser executado via linha de comando (CLI):
 *
 *   php index.php queue_worker work
 *   php index.php queue_worker work default 3     (fila=default, sleep=3s quando vazia)
 *   php index.php queue_worker run_once            (processa 1 job e encerra, bom pra cron)
 *
 * Recomenda-se rodar "work" via Supervisor (Linux) para manter o processo
 * sempre ativo, reiniciando sozinho se cair. Veja README.md.
 */
class Queue_worker extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        // impede acesso via navegador, só roda via terminal
        if (!$this->input->is_cli_request()) {
            show_error('Acesso permitido apenas via linha de comando (CLI).', 403);
        }

        $this->load->database();
        require_once APPPATH . 'libraries/Job.php';
    }

    /**
     * Loop contínuo processando jobs de uma fila.
     */
    public function work($queue = 'default', $sleep = 3)
    {
        echo "[Queue Worker] iniciado na fila '{$queue}'. Pressione CTRL+C para sair.\n";

        while (true) {
            $processed = $this->process_next($queue);

            if (!$processed) {
                sleep((int) $sleep); // nada pra fazer, aguarda antes de checar de novo
            }
        }
    }

    /**
     * Processa um único job e encerra. Útil para chamar via cron a cada minuto
     * em servidores onde não é possível manter um processo em segundo plano.
     */
    public function run_once($queue = 'default')
    {
        $processed = $this->process_next($queue);
        echo $processed ? "1 job processado.\n" : "Nenhum job pendente.\n";
    }

    protected function process_next($queue)
    {
        $this->db->trans_begin();

        // FOR UPDATE trava a linha para evitar que dois workers peguem o mesmo job
        // (exige tabela InnoDB)
        $row = $this->db->query(
            "SELECT * FROM jobs
             WHERE queue = ? AND reserved_at IS NULL AND available_at <= ?
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE",
            [$queue, time()]
        )->row_array();

        if (!$row) {
            $this->db->trans_commit();
            return false;
        }

        $this->db->where('id', $row['id'])
                  ->update('jobs', ['reserved_at' => time()]);

        $this->db->trans_commit();

        $this->run_job($row);

        return true;
    }

    protected function run_job($row)
    {
        $payload  = json_decode($row['payload'], true);
        $jobClass = $payload['job'];
        $data     = $payload['data'];

        $jobFile = APPPATH . 'jobs/' . $jobClass . '.php';

        if (!file_exists($jobFile)) {
            $this->fail_job($row, new Exception("Classe de job '{$jobClass}' não encontrada em application/jobs."));
            return;
        }

        require_once $jobFile;
        $job = null;

        try {
            $job = new $jobClass();
            $job->handle($data);

            // sucesso: remove da fila
            $this->db->where('id', $row['id'])->delete('jobs');

            echo "[OK] Job #{$row['id']} ({$jobClass}) processado com sucesso.\n";
        } catch (Exception $e) {
            $attempts = $row['attempts'] + 1;
            $maxTries = $job ? $job->maxTries : 3;
            $backoff  = $job ? $job->backoff : 10;

            if ($attempts >= $maxTries) {
                $this->fail_job($row, $e);

                if ($job) {
                    $job->failed($data, $e);
                }
            } else {
                // libera o job pra tentar de novo depois do backoff
                $this->db->where('id', $row['id'])->update('jobs', [
                    'attempts'     => $attempts,
                    'reserved_at'  => NULL,
                    'available_at' => time() + $backoff,
                ]);

                echo "[RETRY] Job #{$row['id']} ({$jobClass}) falhou (tentativa {$attempts}/{$maxTries}): {$e->getMessage()}\n";
            }
        }
    }

    protected function fail_job($row, Exception $e)
    {
        $this->db->insert('failed_jobs', [
            'queue'     => $row['queue'],
            'payload'   => $row['payload'],
            'exception' => $e->getMessage(),
            'failed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->db->where('id', $row['id'])->delete('jobs');

        echo "[FAILED] Job #{$row['id']} movido para failed_jobs: {$e->getMessage()}\n";
    }
}
