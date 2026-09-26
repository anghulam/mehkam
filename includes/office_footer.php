    </div><!-- /mk-content -->

    <footer class="mk-footer">
      <span>© <?= date('Y') ?> مِحكام — نظام إدارة مكاتب المحاماة</span>
      &nbsp;·&nbsp;
      <a href="profile.php?tab=subscription">باقتك: <?= e($_SESSION['pkg_name'] ?? '—') ?></a>
    </footer>

  </div><!-- /mk-main -->
</div><!-- /mk-wrapper -->

<!-- Bootstrap 5 Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- مِحكام JS -->
<script src="../js/mehkam.js?v=<?= @filemtime(__DIR__ . '/../js/mehkam.js') ?: '5' ?>"></script>
<!-- محوّل التاريخ الهجري لحقول الإدخال -->
<script>window.MEHKAM_CAL = <?= json_encode(function_exists('calPref') ? calPref() : 'gregorian') ?>;</script>
<script src="../js/hijri.js?v=<?= @filemtime(__DIR__ . '/../js/hijri.js') ?: '4' ?>"></script>
</body>
</html>
