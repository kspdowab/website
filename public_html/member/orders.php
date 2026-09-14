<?php
/**
 * KSPDOWA — Member: Orders redirect
 */
declare(strict_types=1);
header('Location: /member/orders-circulars.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit;
