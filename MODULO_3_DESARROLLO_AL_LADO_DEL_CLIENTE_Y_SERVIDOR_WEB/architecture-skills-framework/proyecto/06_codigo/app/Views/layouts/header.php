<?php
/** CP-FRONT-01: cabeza común. $pageTitle obligatorio desde la View. */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'S3IH - Sistema Hospitalario', ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/assets/css/variables.css">
<link rel="stylesheet" href="/assets/css/layout.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/forms.css">
<link rel="stylesheet" href="/assets/css/responsive.css">
</head>
