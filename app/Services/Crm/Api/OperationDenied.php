<?php

namespace App\Services\Crm\Api;

use RuntimeException;

/**
 * Операция запрещена: нет права либо она вовсе закрыта для машинного вызова.
 *
 * Отдельный тип, а не AuthorizationException, потому что причина отказа должна
 * дойти до агента текстом: «нет права» и «удаление через агента запрещено» —
 * разные ситуации, и на первую он может попросить доступ, а на вторую нет.
 */
class OperationDenied extends RuntimeException {}
