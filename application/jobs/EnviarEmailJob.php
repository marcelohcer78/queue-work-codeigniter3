
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Job.php';

/**
 * Exemplo de job: envia um e-mail usando a lib nativa do CodeIgniter.
 *
 * Disparo:
 *   $this->load->library('queue');
 *   $this->queue->push('EnviarEmailJob', [
 *       'to'      => 'cliente@exemplo.com',
 *       'subject' => 'Bem-vindo',
 *       'message' => 'Olá! Sua conta foi criada.',
 *   ]);
 */
class EnviarEmailJob extends CI_Job
{
    public $maxTries = 3;
    public $backoff = 30; // segundos entre tentativas

    public function handle($data)
    {
        $this->CI->load->library('email');

        $this->CI->email->from('sistema@seudominio.com.br', 'Sistema');
        $this->CI->email->to($data['to']);
        $this->CI->email->subject($data['subject'] ?? 'Sem assunto');
        $this->CI->email->message($data['message'] ?? '');

        if (!$this->CI->email->send()) {
            // lançar exceção faz o worker registrar tentativa e tentar de novo depois
            throw new Exception('Falha ao enviar e-mail: ' . $this->CI->email->print_debugger(['headers']));
        }
    }

    public function failed($data, $exception)
    {
        log_message('error', 'EnviarEmailJob falhou definitivamente para ' . $data['to'] . ': ' . $exception->getMessage());
    }
}
