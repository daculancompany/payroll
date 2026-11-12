<?php
/* v2 theme layer, end of <body> on standalone pages: recolours the page's own
 * components to the active theme. $V2_ADAPT_SCOPE (CSS selector) limits it. */
?>
    <script>window.V2_ADAPT_SCOPE = <?= json_encode($V2_ADAPT_SCOPE ?? 'body > *:not(script)') ?>;
    window.V2_PAPER_SEL = <?= json_encode($V2_PAPER_SEL ?? '') ?>;</script>
    <script src="<?= av('assets3/js/theme-adapter.js') ?>"></script>
