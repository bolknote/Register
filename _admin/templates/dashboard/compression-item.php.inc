<?php
/**
 * @var callable $trans
 * @var list<array{
 *     encoding:string,
 *     label:string,
 *     php_available:bool,
 *     build_available:bool,
 *     sidecar_count:int
 * }> $codecs
 * @var bool $responseCacheEnabled
 */
?>
<div class="stat-item compression-stat-item">
    <h3><?= $trans('Response compression') ?></h3>
    <dl class="stat-details compression-details">
        <?php foreach ($codecs as $codec): ?>
            <div>
                <dt><?= htmlspecialchars($codec['label'], ENT_QUOTES, 'UTF-8') ?> <code><?= htmlspecialchars($codec['encoding'], ENT_QUOTES, 'UTF-8') ?></code></dt>
                <dd>
                    <?= $trans($codec['php_available'] ? 'Dynamic compression available' : 'Dynamic compression unavailable') ?>
                    <small class="technical-data"><?= $trans(
                        $codec['build_available'] ? 'Asset precompression available' : 'Asset precompression unavailable',
                    ) ?> · <?= $trans('Compressed asset variants', [
                        '{{ count }}' => $codec['sidecar_count'],
                        '%count%'     => $codec['sidecar_count'],
                    ]) ?></small>
                </dd>
            </div>
        <?php endforeach; ?>
        <div>
            <dt><?= $trans('Encoded page cache') ?></dt>
            <dd><?= $trans($responseCacheEnabled ? 'Compression cache enabled' : 'Compression cache disabled') ?></dd>
        </div>
    </dl>
    <p class="technical-data"><?= $trans('Compression server note') ?></p>
</div>
