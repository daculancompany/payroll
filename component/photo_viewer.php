<?php
/**
 * One click-to-enlarge photo viewer, shared by every screen that shows a
 * kiosk image: the registered-face tiles (component/face_status.php), the
 * punch thumbnails on Punch Logs and PIN Attendance, and the employee
 * portal's My Scans.
 *
 * A face tile is 74px and a punch thumbnail 48px — too small to tell two
 * people apart, which is the whole reason the photo is kept. Every one of
 * those screens needed the same overlay, so it lives here once.
 *
 *   echo render_photo_viewer();                       // once per page
 *   <img onclick="kioskPhotoView(this.src, 'Caption')">
 *
 * Emitting it twice is harmless: the markup is written only on the first
 * call, and the JS guards itself.
 */

if (!function_exists('render_photo_viewer')) {

    function render_photo_viewer()
    {
        static $done = false;
        if ($done) return '';
        $done = true;

        ob_start(); ?>
        <div class="kphoto-viewer" id="kphoto-viewer" role="dialog" aria-modal="true" aria-label="Photo">
            <figure class="kphoto-figure">
                <?php /* The close button sits on the photo's own corner, not the
                        screen's. A face tile is 160px wide, so a button pinned to
                        the top right of a 1440px window ends up a long way from
                        the thing it closes. */ ?>
                <div class="kphoto-shot">
                    <img alt="" id="kphoto-img">
                    <button type="button" class="kphoto-close" aria-label="Close">&times;</button>
                </div>
                <figcaption id="kphoto-cap"></figcaption>
            </figure>
        </div>
        <style>
        .kphoto-viewer{position:fixed;inset:0;background:rgba(8,10,18,.86);display:none;align-items:center;justify-content:center;z-index:3000;padding:24px;}
        .kphoto-viewer.show{display:flex;}
        .kphoto-figure{margin:0;max-width:100%;max-height:100%;display:flex;flex-direction:column;align-items:center;gap:10px;}
        .kphoto-shot{position:relative;display:inline-block;max-width:100%;line-height:0;}
        .kphoto-figure img{max-width:min(720px,92vw);max-height:78vh;border-radius:12px;box-shadow:0 24px 70px rgba(0,0,0,.55);background:#000;}
        .kphoto-figure figcaption{color:#e8e6ef;font-size:13px;font-weight:600;text-align:center;}
        .kphoto-figure figcaption:empty{display:none;}
        /* On the photo's corner, half over the edge: close to the image at any
           size, and solid rather than translucent so it stays readable over a
           bright picture. */
        .kphoto-close{position:absolute;top:-13px;right:-13px;width:34px;height:34px;border-radius:50%;border:2px solid rgba(255,255,255,.9);background:#1b2030;color:#fff;font-size:21px;line-height:0;padding:0;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(0,0,0,.5);transition:background .15s ease, transform .15s ease;}
        .kphoto-close:hover{background:#d64550;transform:scale(1.08);}
        .kphoto-close:focus-visible{outline:2px solid #fff;outline-offset:2px;}
        </style>
        <script>
        (function () {
            if (window.kioskPhotoView) return;
            function box() { return document.getElementById('kphoto-viewer'); }
            window.kioskPhotoView = function (src, caption) {
                var v = box();
                if (!v || !src) return;
                document.getElementById('kphoto-img').src = src;
                document.getElementById('kphoto-cap').textContent = caption || '';
                v.classList.add('show');
            };
            window.kioskPhotoClose = function () {
                var v = box();
                if (v) v.classList.remove('show');
            };
            // Clicking the backdrop or the button closes; clicking the photo does not.
            document.addEventListener('click', function (e) {
                if (e.target.closest('.kphoto-close')) { window.kioskPhotoClose(); return; }
                var v = e.target.closest('.kphoto-viewer');
                if (v && !e.target.closest('.kphoto-figure img')) window.kioskPhotoClose();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') window.kioskPhotoClose();
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
