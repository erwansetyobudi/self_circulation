<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($page_title ?? 'Self Circulation', ENT_QUOTES, 'UTF-8') ?></title>
  <!-- PERBAIKAN VIEWPORT UNTUK TOUCH -->
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=yes, shrink-to-fit=no">

  <!-- CSS bawaan SLiMS -->
  <link rel="stylesheet" href="<?= SWB ?>template/default/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= SWB ?>template/default/assets/plugin/font-awesome/css/fontawesome-all.min.css">

  <style>
    /* PERBAIKAN UNTUK TOUCH SCROLL */
    html, body {
      margin: 0;
      padding: 0;
      width: 100%;
      height: 100%;
      overflow-x: hidden;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch; /* PENTING: Untuk smooth scroll di iOS */
      overscroll-behavior: auto; /* Untuk Android */
    }
    
    body {
      background: #f6f7fb;
    }

    @media print {
      .no-print { display: none !important; }
      body { background: #fff !important; }
    }
  </style>
</head>
<body>
  <?= $main_content ?>
  <script src="<?= SWB ?>template/default/assets/js/jquery.min.js"></script>
  <script src="<?= SWB ?>template/default/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
