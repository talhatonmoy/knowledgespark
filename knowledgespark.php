<?php
/**
 * Plugin Name: KnowledgeSpark
 * Description: Reader-focused internal link suggestions powered by Qdrant Cloud and optional AI ranking.
 * Version: 0.0.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: KnowledgeSpark
 * License: GPL-2.0-or-later
 * Text Domain: knowledgespark
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/includes/class-knowledgespark.php';
KnowledgeSpark::boot();
