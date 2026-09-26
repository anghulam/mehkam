    </div><!-- /mk-content -->
    <footer class="mk-footer">
      © <?= date('Y') ?> مِحكام — لوحة إدارة النظام
    </footer>
  </div><!-- /mk-main -->
</div><!-- /mk-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/mehkam.js?v=<?= @filemtime(__DIR__ . '/../js/mehkam.js') ?: '5' ?>"></script>
<script>window.MEHKAM_CAL = 'both';</script>
<script src="../js/hijri.js?v=<?= @filemtime(__DIR__ . '/../js/hijri.js') ?: '5' ?>"></script>
</body>
</html>
