<?php
/**
 * Pagination partial. Expects:
 *   int $page    current page (1-based)
 *   int $pages   total number of pages
 *   string $q, string $sort, string $dir   params to preserve
 *   array $extra (optional)                additional filter params to preserve
 */
$extra = $extra ?? [];
if ($pages > 1):
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $pageUrl = static function (int $p) use ($path, $q, $sort, $dir, $extra): string {
        $params = array_merge($extra, ['page' => $p, 'sort' => $sort, 'dir' => $dir]);
        if ($q !== '') {
            $params['q'] = $q;
        }
        $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
        return $path . '?' . http_build_query($params);
    };
    $window = 2;
    $start = max(1, $page - $window);
    $end = min($pages, $page + $window);
?>
<nav class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <span class="text-muted" style="font-size:.8rem">Page <?= (int) $page ?> / <?= (int) $pages ?></span>
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e($pageUrl(max(1, $page - 1))) ?>"><i class="bi bi-chevron-left"></i></a>
        </li>
        <?php if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= e($pageUrl(1)) ?>">1</a></li>
            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php endif; ?>
        <?php for ($p = $start; $p <= $end; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= e($pageUrl($p)) ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
        <?php if ($end < $pages): ?>
            <?php if ($end < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= e($pageUrl($pages)) ?>"><?= (int) $pages ?></a></li>
        <?php endif; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e($pageUrl(min($pages, $page + 1))) ?>"><i class="bi bi-chevron-right"></i></a>
        </li>
    </ul>
</nav>
<?php endif; ?>
