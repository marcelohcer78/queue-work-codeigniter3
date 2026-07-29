# Queue para CodeIgniter 3

Sistema de fila de processamento em background para CI3, inspirado no `queue:work`
do Laravel. Usa uma tabela no banco (`jobs`) como driver, com retry automático,
backoff e uma tabela de falhas (`failed_jobs`) — sem depender de Redis/Beanstalkd.

## Instalação

1. Rode o SQL em `sql/create_queue_tables.sql` no seu banco.
2. Copie as pastas para o seu projeto CI3:
   - `application/libraries/Queue.php`
   - `application/libraries/Job.php`
   - `application/controllers/Queue_worker.php`
   - `application/jobs/` (pasta nova — coloque seus jobs aqui)

## Como enfileirar um job

Em qualquer Controller/Model:

```php
$this->load->library('queue');

// executa assim que o worker pegar
$this->queue->push('EnviarEmailJob', [
    'to'      => 'cliente@exemplo.com',
    'subject' => 'Bem-vindo',
    'message' => 'Sua conta foi criada com sucesso.',
]);

// executa daqui a 5 minutos (300s)
$this->queue->later(300, 'EnviarEmailJob', ['to' => 'cliente@exemplo.com']);

// fila separada (ex.: relatórios pesados não travam a fila de e-mails)
$this->queue->push('GerarRelatorioJob', ['user_id' => 42], 'relatorios');
```

## Criando um novo Job

Crie um arquivo em `application/jobs/MeuJob.php`:

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'libraries/Job.php';

class MeuJob extends CI_Job
{
    public $maxTries = 3; // tentativas antes de ir pra failed_jobs
    public $backoff  = 15; // segundos entre tentativas

    public function handle($data)
    {
        // acesso normal ao CI dentro do job: $this->CI->load->model(...)
        $this->CI->load->model('Pedido_model');
        $this->CI->Pedido_model->processar($data['pedido_id']);

        // lance Exception para forçar retry
        // throw new Exception('algo deu errado');
    }

    public function failed($data, $exception)
    {
        // chamado quando esgota as tentativas
        log_message('error', 'MeuJob falhou: ' . $exception->getMessage());
    }
}
```

## Rodando o worker

```bash
# processa continuamente (fica rodando em loop)
php index.php queue_worker work

# fila específica + tempo de espera quando vazia
php index.php queue_worker work relatorios 5

# processa 1 job e encerra (bom para cron a cada minuto)
php index.php queue_worker run_once
```

### Mantendo o worker sempre ativo (produção)

Use o **Supervisor** (Linux) para reiniciar o worker automaticamente se ele cair:

```ini
; /etc/supervisor/conf.d/ci3-queue-worker.conf
[program:ci3-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /caminho/do/projeto/index.php queue_worker work default 3
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/ci3-queue-worker.log
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start ci3-queue-worker:*
```

Se preferir não deixar processo em background (ex.: hospedagem compartilhada),
use `run_once` num cron a cada minuto:

```
* * * * * php /caminho/do/projeto/index.php queue_worker run_once >> /dev/null 2>&1
```

## Como funciona por baixo dos panos

- `push()`/`later()` gravam uma linha em `jobs` com o payload em JSON
  (classe do job + dados) e `available_at` (timestamp de quando pode rodar).
- O worker faz `SELECT ... FOR UPDATE` para pegar o próximo job disponível
  de forma segura mesmo com múltiplos workers rodando ao mesmo tempo.
- Se `handle()` lançar uma Exception, o job é liberado de novo com
  `attempts + 1` e `available_at` empurrado pelo valor de `backoff`.
- Ao atingir `maxTries`, o job sai de `jobs` e vai para `failed_jobs`
  junto com a mensagem de erro, e `failed()` é chamado no job.

## Limitações (comparado ao Laravel)

- Sem suporte nativo a Redis/SQS — só banco de dados (o que cobre a
  grande maioria dos casos de uso reais).
- Sem "chains" ou "batches" de jobs prontos — dá pra simular disparando
  o próximo job dentro do `handle()` do anterior.
- Sem dashboard tipo Horizon — para inspecionar, consulte as tabelas
  `jobs` e `failed_jobs` diretamente (ou monte uma tela simples no AdminLTE).
