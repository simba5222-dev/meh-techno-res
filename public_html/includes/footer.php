</div>
<?php if (!empty($user)): ?>
  </main>
</div>
<?php endif; ?>
<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/../assets/app.js') ?: time() ?>"></script>
</body>
</html>
