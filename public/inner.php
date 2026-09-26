<?php require_once '../config/db.php'; require_once '../includes/content_helper.php'; ?>
<?php
$page_title = sc($conn,'site_name','OLFS');
include 'includes/header.php';
?>

<section class="page-hero">
  <div class="container position-relative" style="z-index:1">
    <h1 class="section-title">الصفحة غير موجودة</h1>
    <p class="section-desc">الصفحة التي تبحث عنها غير متاحة حالياً</p>
    <div class="mt-4">
      <a href="home.php" class="btn-hero-primary d-inline-flex"><i class="fas fa-home"></i> العودة للرئيسية</a>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>
