<?php
/**
 * Caminhos absolutos centralizados do projeto.
 * Seguro para múltiplos includes.
 */

if (defined('ROOT_PATH')) return;

define('ROOT_PATH',    dirname(__DIR__));
define('APP_PATH',     ROOT_PATH . '/app');
define('CONFIG_PATH',  ROOT_PATH . '/config');
define('INCLUDES_PATH',ROOT_PATH . '/includes');
define('LIB_PATH',     ROOT_PATH . '/lib');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('ASSETS_PATH',  ROOT_PATH . '/assets');
define('DATABASE_PATH',ROOT_PATH . '/database');

define('STORAGE_FOTOS',        STORAGE_PATH . '/fotos/');
define('STORAGE_MANIFESTACOES',STORAGE_PATH . '/manifestacoes/');

define('ASSETS_URL',  'assets');
define('STORAGE_URL', 'storage');
