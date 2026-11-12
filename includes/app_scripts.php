<?php
/* Vendor scripts + global helpers (handleError, _conf, toastr setup, the
 * select2 shim, DataTables defaults, notifications poller). Shared by both
 * shells: index.php (classic) and app.php (v2). Expects #notif-* elements in
 * the topbar. Moved verbatim out of index.php. */
?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js" integrity="sha256-xNzN2a4ltkB44Mc/Jz3pT4iU1cmeR0FkXs4pru/JxaQ=" crossorigin="anonymous"></script>
    <!-- JAVASCRIPT -->
<?php if (!empty($APP_V2)) { ?>
    <!-- v2 shell: open-source libraries only, no template JS (assets/js/app.js
         and plugins.js belong to the Velzon template and drive its layout). -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/simplebar@6.2.7/dist/simplebar.min.js"></script>
<?php } else { ?>
    <script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="assets/libs/simplebar/simplebar.min.js"></script>
    <script src="assets/libs/node-waves/waves.min.js"></script>
    <script src="assets/libs/feather-icons/feather.min.js"></script>
    <script src="assets/js/pages/plugins/lord-icon-2.1.0.js"></script>
    <script src="assets/js/plugins.js"></script>
    <!-- App js -->
    <script src="assets/js/app.js"></script>
<?php } ?>
    <!-- Firebase Cloud Messaging: browser push for new attendance (works with the site closed) -->
    <script type="module" src="assets2/js/fcm-client.js"></script>
    <script src="assets2/vendor/toastr/toastr.js"></script>
    <!-- Parsley.js CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/parsley.js/2.9.2/parsley.min.js"></script>

    <!-- <script src="assets2/bundles/datatablescripts.bundle.js"></script> -->
    <!-- Bootstrap-Select JS (replaces Select2) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>
    <script>
        /* ── Compatibility shim: route legacy .select2() calls to bootstrap-select ──
       Lets every existing page keep calling $(...).select2(...) while rendering
       the snapappointments bootstrap-select dropdown instead. */
        (function($) {
            if (!$.fn.selectpicker) return;
            $.fn.select2 = function(arg, val) {
                return this.each(function() {
                    var $el = $(this);
                    // Method-style calls: .select2('val', x) / .select2('destroy') / .select2('open')
                    if (typeof arg === 'string') {
                        if (arg === 'val') {
                            $el.val(val === undefined ? '' : val).selectpicker('refresh');
                        } else if (arg === 'destroy') {
                            if ($el.data('bs-select')) {
                                $el.selectpicker('destroy');
                                $el.removeData('bs-select');
                            }
                        } else if (arg === 'open' || arg === 'toggle') {
                            $el.selectpicker('toggle');
                        } else if (arg === 'close') {
                            $el.selectpicker('hide');
                        }
                        return;
                    }
                    var opts = arg || {};
                    if (!$el.data('bs-select')) {
                        // A lazily-initialised select (e.g. .select2() called on
                        // modal show) may already carry the global CustomSelect
                        // control. Hand over cleanly instead of stacking two UIs.
                        if (window.CustomSelect && this._cs) window.CustomSelect.destroy(this);
                        $el.addClass('selectpicker');
                        // only show a search box when the list is long enough to need it
                        if ($el.find('option').length > 8) $el.attr('data-live-search', 'true');
                        // placeholder → title
                        if (opts.placeholder) {
                            $el.attr('title', typeof opts.placeholder === 'string' ? opts.placeholder : (opts.placeholder.text || ''));
                        }
                        // width
                        var w = opts.width;
                        $el.attr('data-width', (!w || w === 'resolve' || w === 'element') ? '100%' : w);
                        // multi-select: compact count display (no bulky select-all bar)
                        if ($el.prop('multiple')) $el.attr('data-selected-text-format', 'count > 2');
                        // Inside a modal, keep the dropdown menu within the modal's own DOM
                        // subtree (not <body>) — Bootstrap 5's modal focus trap forces focus
                        // back into the modal on any focusin outside it, which was stealing
                        // focus from the live-search input the instant you tried to type.
                        var $modal = $el.closest('.modal');
                        $el.selectpicker($modal.length ? {
                            container: $modal
                        } : {});
                        // keep the button label in sync when code does .val(...).trigger('change')
                        // use 'render' (button only) — NOT 'refresh', which rebuilds the open
                        // menu and duplicates items when the user clicks an option.
                        $el.on('change.bsshim', function() {
                            $el.selectpicker('render');
                        });
                        $el.data('bs-select', true);
                    } else {
                        $el.selectpicker('refresh');
                    }
                });
            };
        })(jQuery);
    </script>

    <!-- App-wide custom <select> control. Must load AFTER the bootstrap-select
         shim above (so selects claimed by .select2() are skipped) and BEFORE the
         page scripts below (so its DOMContentLoaded sweep runs after theirs). -->
    <script src="<?= av('assets2/js/custom-select.js') ?>"></script>
    <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/corejs-typeahead/1.3.0/typeahead.bundle.min.js"></script> -->

    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.2.9/js/dataTables.responsive.min.js"></script>
    <script>
        // Uniform empty-state design for every DataTable (deep-merged, so per-table
        // language overrides like searchPlaceholder still apply).
        if (window.jQuery && jQuery.fn.dataTable) {
            jQuery.extend(true, jQuery.fn.dataTable.defaults, {
                language: {
                    emptyTable: '<div class="dt-empty-state">'
                        + '<div class="dt-empty-ic"><i class="ri-inbox-line"></i></div>'
                        + '<p class="dt-empty-title">No records yet</p>'
                        + '<span class="dt-empty-sub">Nothing has been added here yet.</span>'
                        + '</div>',
                    zeroRecords: '<div class="dt-empty-state">'
                        + '<div class="dt-empty-ic dt-empty-ic-search"><i class="ri-search-eye-line"></i></div>'
                        + '<p class="dt-empty-title">No matching records</p>'
                        + '<span class="dt-empty-sub">Try a different search term.</span>'
                        + '</div>'
                }
            });
        }
    </script>


    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/css/bootstrap-datetimepicker.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/js/bootstrap-datetimepicker.min.js"></script>
    <!-- Date range picker (daterangepicker.com) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>

    <script type="text/javascript">
        $(function() {
            $('[data-toggle="tooltip"]').tooltip()
        })



        toastr.options.timeOut = "false";
        toastr.options.closeButton = true;
        toastr.options.positionClass = 'toast-top-right';


        function handleError(e) {
            $('.submitbutton').removeAttr('disabled');
            $(".fa-spinner-button").hide();
            toastr['error'](e ? e : 'Someting went wrong. Please contact administrator.', "Error Notification");
        }

        $('.filterme').keypress(function(eve) {
            if ((eve.which != 46 || $(this).val().indexOf('.') != -1) && (eve.which < 48 || eve.which > 57) || (eve.which == 46 && $(this).caret().start == 0)) {
                eve.preventDefault();
            }
            $('.filterme').keyup(function(eve) {
                if ($(this).val().indexOf('.') == 0) {
                    $(this).val($(this).val().substring(1));
                }
            });
        });

        function _conf($msg = '', $func = '', $params = []) {
            Swal.fire({
                title: 'Confirmation',
                text: $msg,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonColor: '#2196F3',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'No, cancel!',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    window[$func]($params.join(','));
                }
            })
        }

        /* ── Notifications (server-side paginated, infinite scroll) ── */
        (function() {
            const badge = document.getElementById('notif-badge');
            const list = document.getElementById('notif-list');
            const unreadLabel = document.getElementById('notif-unread-label');
            if (!badge || !list) return;

            const PAGE_SIZE = 15;
            let offset = 0;
            let hasMore = true;
            let loading = false;

            function timeAgo(ts) {
                const diff = Math.floor((Date.now() - new Date(ts.replace(' ', 'T'))) / 1000);
                if (diff < 60) return 'just now';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
                return new Date(ts.replace(' ', 'T')).toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric'
                });
            }

            // Notification text can contain employee-typed content (dispute comments,
            // leave reasons) — always escape before injecting into innerHTML.
            function escNotif(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            function itemHtml(n) {
                const unread = n.is_read == 0;
                const link = /^(javascript|data|vbscript):/i.test(String(n.link || '').trim()) ? '' : (n.link || '');
                return '<a href="' + (escNotif(link) || 'javascript:void(0)') + '" class="text-reset notification-item d-block dropdown-item position-relative px-3 py-2" ' +
                    'data-id="' + n.id + '" style="border-bottom:1px solid #f3f3f3;' + (unread ? 'background:#f6f4fa;' : '') + '">' +
                    '<div class="d-flex">' +
                    '<div class="me-3 flex-shrink-0"><span class="avatar-title bg-' + (n.color || 'primary') + '-subtle text-' + (n.color || 'primary') + ' rounded-circle fs-16" style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;"><i class="' + (n.icon || 'ri-notification-3-line') + '"></i></span></div>' +
                    '<div class="flex-grow-1">' +
                    '<h6 class="mt-0 mb-1 fs-13 fw-semibold">' + escNotif(n.title) + (unread ? ' <span class="badge bg-success ms-1" style="font-size:8px;vertical-align:middle;">NEW</span>' : '') + '</h6>' +
                    '<div class="fs-12 text-muted mb-1">' + escNotif(n.message) + '</div>' +
                    '<p class="mb-0 fs-11 text-muted"><i class="mdi mdi-clock-outline"></i> ' + timeAgo(n.created_at) + '</p>' +
                    '</div></div></a>';
            }

            function bindItemClicks(container) {
                container.querySelectorAll('.notification-item').forEach(function(el) {
                    el.addEventListener('click', function() {
                        const id = this.getAttribute('data-id');
                        fetch('ajax.php?action=mark_notification_read', {
                            method: 'POST',
                            body: new URLSearchParams({
                                id
                            })
                        }).then(function() {
                            load(true);
                        });
                    });
                });
            }

            function setSpinner(on) {
                let sp = list.querySelector('#notif-load-more-spinner');
                if (on) {
                    if (!sp) {
                        sp = document.createElement('div');
                        sp.id = 'notif-load-more-spinner';
                        sp.className = 'text-center py-2 text-muted';
                        sp.innerHTML = '<i class="ri-loader-4-line ri-spin fs-16"></i>';
                        list.appendChild(sp);
                    }
                } else if (sp) {
                    sp.remove();
                }
            }

            // reset=true → fresh first page (dropdown open, mark-read, mark-all, 30s refresh).
            // reset=false → append the next page (triggered by scrolling near the bottom).
            function load(reset) {
                if (loading) return;
                if (!reset && !hasMore) return;
                loading = true;
                if (reset) offset = 0;
                if (!reset) setSpinner(true);

                fetch('ajax.php?action=get_notifications', {
                        method: 'POST',
                        body: new URLSearchParams({
                            limit: PAGE_SIZE,
                            offset: offset
                        })
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(res) {
                        setSpinner(false);
                        if (!res || !res.result) {
                            loading = false;
                            return;
                        }

                        const u = res.unread || 0;
                        badge.textContent = u > 99 ? '99+' : u;
                        badge.style.display = u > 0 ? '' : 'none';
                        if (unreadLabel) unreadLabel.textContent = u + ' New';

                        const items = res.items || [];
                        hasMore = !!res.has_more;

                        if (reset) {
                            if (!items.length) {
                                list.innerHTML = '<div class="text-center p-4 text-muted"><i class="ri-notification-off-line fs-22 d-block mb-1"></i>No notifications</div>';
                            } else {
                                list.innerHTML = items.map(itemHtml).join('');
                                bindItemClicks(list);
                            }
                        } else if (items.length) {
                            const frag = document.createElement('div');
                            frag.innerHTML = items.map(itemHtml).join('');
                            while (frag.firstChild) list.appendChild(frag.firstChild);
                            bindItemClicks(list);
                        }

                        offset += items.length;
                        loading = false;
                    }).catch(function() {
                        setSpinner(false);
                        loading = false;
                    });
            }

            // Infinite scroll: fetch the next page once the user nears the bottom.
            list.addEventListener('scroll', function() {
                if (loading || !hasMore) return;
                if (list.scrollTop + list.clientHeight >= list.scrollHeight - 40) {
                    load(false);
                }
            });

            const markAll = document.getElementById('notif-mark-all');
            if (markAll) markAll.addEventListener('click', function() {
                fetch('ajax.php?action=mark_all_notifications_read', {
                    method: 'POST'
                }).then(function() {
                    load(true);
                });
            });

            load(true);
            setInterval(function() {
                load(true);
            }, 30000); // refresh every 30s (resets back to the first page)
        })();
    </script>

