
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Classe base para Jobs da fila.
 * Todo job criado em application/jobs deve estender esta classe.
 */
abstract class CI_Job
{
    /** @var int Número máximo de tentativas antes de mover para failed_jobs */
    public $maxTries = 3;

    /** @var int Segundos de espera entre tentativas (backoff) */
    public $backoff = 10;

    /** @var CI_Controller instância do CodeIgniter, disponível dentro do job */
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    /**
     * Lógica principal do job. Implemente nas subclasses.
     * Lance uma Exception dentro de handle() para sinalizar falha e
     * fazer o worker tentar novamente (respeitando maxTries/backoff).
     *
     * @param array $data Dados enviados no push()/later()
     */
    abstract public function handle($data);

    /**
     * Chamado quando o job falha definitivamente (esgotou as tentativas).
     * Sobrescreva para, por exemplo, notificar um admin.
     */
    public function failed($data, $exception)
    {
        // opcional: sobrescrever nas subclasses
    }
}
