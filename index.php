<?php
// error_reporting(0);
$allowed_values = array(1, 2, 3, 4);
$allowed_values_2 = array(1, 2, 3);
?>
<?php
$clasification_array = ['bg-primary', 'bg-secondary', 'bg-warning', 'bg-danger', 'bg-dark', 'bg-info', 'bg-primary']
?>
<?php include 'db_connect.php'; ?>
<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
// The offline payroll machine (APP_ROLE=local) is admin-only — it never serves
// the employee portal, so an employee session here is meaningless.
if (!app_is_local()) {
    // Employees (employee portal session) must NOT reach admin pages.
    if ((!isset($_SESSION['is_login']) || !$_SESSION['is_login'])
        && isset($_SESSION['emp_is_login']) && $_SESSION['emp_is_login']
    ) {
        header('location:employee-portal.php');
        exit;
    }
}
if (!isset($_SESSION['is_login']) || !$_SESSION['is_login']) {
    header('location:login.php');
    exit;
}
// Users who chose the v2 interface (users.settings.ui = 'v2') land in app.php.
// Cached in the session; Action::save_ui_settings keeps it current.
if (!isset($_SESSION['ui_shell'])) {
    $_SESSION['ui_shell'] = 'classic';
    $__uid = (int) ($_SESSION['login_id'] ?? 0);
    if ($__uid > 0 && ($__st = $conn->prepare('SELECT settings FROM users WHERE id = ?'))) {
        $__st->bind_param('i', $__uid);
        if ($__st->execute()) {
            $__r = $__st->get_result()->fetch_assoc();
            $__set = $__r && $__r['settings'] ? (json_decode($__r['settings'], true) ?: []) : [];
            $_SESSION['ui_shell'] = ($__set['ui'] ?? '') === 'v2' ? 'v2' : 'classic';
        }
        $__st->close();
    }
}
$_SESSION['in_v2'] = false;   // standalone pages follow the classic look
if ($_SESSION['ui_shell'] === 'v2' && empty($_GET['classic'])) {
    $__q = $_GET;
    $__p = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($__q['page'] ?? ''));
    unset($__q['page']);
    header('Location: app' . ($__p !== '' ? '/' . $__p : '') . ($__q ? '?' . http_build_query($__q) : ''));
    exit;
}
// Payroll details now lives on its own full-viewport page (like dtr-documents.php);
// old index.php?page=payroll_calculations&id=N links land there.
if (($_GET['page'] ?? '') === 'payroll_calculations') {
    header('Location: payroll_calculations.php?id=' . (int)($_GET['id'] ?? 0));
    exit;
}
// The duty roster is a full-viewport grid on its own page (like dtr-documents.php),
// so ?page=duty-roster links from bookmarks or older builds land there.
if (($_GET['page'] ?? '') === 'duty-roster') {
    header('Location: duty-roster.php');
    exit;
}
?>

<?php include 'includes/header.php' ?>
<style>
    .table-dark th {
        background-color: #673bb6 !important;
        border-color: #6b46b2 !important;
    }
</style>
<?php
$login_role = $_SESSION['login_role'];

function getRole($login_role)
{
    switch ($login_role) {
        case 1:
            return "Administrator";
            break;
        case 2:
            return "Staff";
            break;
        case 3:
            return "Auditor";
            break;
        case 4:
            return "Payroll Clerk";
            break;
        case 5:
            return "Timekeeper";
            break;
        case 6:
            return "PIC";
            break;
        case 7:
            return "Auditor";
            break;
        case 8:
            return "Department Head";
            break;
        case 9:
            return "HR";
            break;
        case 10:
            return "Supervisor";
            break;
        case 11:
            return "Section/Unit Head";
            break;
        default:
            return "Access denied. Unknown role.";
            break;
    }
}
?>


<body>

    <!-- Page loader — must be FIRST so it appears before any content -->
    <style>
        #jp-loader {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(circle at 30% 20%, #f2eff9 0, transparent 55%),
                radial-gradient(circle at 75% 80%, #ece8f4 0, transparent 50%),
                #ffffff;
            transition: opacity .5s ease, visibility .5s ease;
        }

        #jp-loader.jp-loader-hidden {
            opacity: 0;
            visibility: hidden;
        }

        .jp-loader-content {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Logo with single orbiting ring */
        .jp-loader-ring {
            position: relative;
            width: 96px;
            height: 96px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .jp-loader-ring::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 3px solid #e9e5f2;
            border-top-color: #6642aa;
            animation: jpSpin 1s linear infinite;
        }

        .jp-loader-logo {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #fff;
            background: linear-gradient(135deg, #6642aa, #4e3483);
            box-shadow: 0 10px 26px rgba(102, 66, 170, .40);
            animation: jpPulse 1.6s ease-in-out infinite;
        }

        .jp-loader-brand {
            margin-top: 20px;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: .3px;
            background: linear-gradient(90deg, #4e3483, #7653bb);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .jp-loader-bar {
            position: relative;
            overflow: hidden;
            margin-top: 16px;
            width: 170px;
            height: 4px;
            border-radius: 4px;
            background: #e9e5f2;
        }

        .jp-loader-bar span {
            position: absolute;
            left: -45%;
            width: 45%;
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, #6642aa, #7653bb);
            animation: jpSlide 1.1s cubic-bezier(.65, .05, .36, 1) infinite;
        }

        .jp-loader-text {
            margin-top: 12px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #a7a0b7;
        }

        .jp-loader-text b {
            display: inline-block;
            animation: jpBlink 1.4s infinite;
        }

        .jp-loader-text b:nth-child(2) {
            animation-delay: .2s;
        }

        .jp-loader-text b:nth-child(3) {
            animation-delay: .4s;
        }

        @keyframes jpSpin {
            to {
                transform: rotate(360deg)
            }
        }

        @keyframes jpPulse {

            0%,
            100% {
                transform: scale(1)
            }

            50% {
                transform: scale(1.06)
            }
        }

        @keyframes jpSlide {
            0% {
                left: -45%
            }

            60% {
                left: 55%
            }

            100% {
                left: 110%
            }
        }

        @keyframes jpBlink {

            0%,
            100% {
                opacity: .25
            }

            50% {
                opacity: 1
            }
        }
    </style>
    <!-- <div id="jp-loader">
        <div class="jp-loader-content">
            <div class="jp-loader-ring">
                <div class="jp-loader-logo">HR</div>
            </div>
            <div class="jp-loader-brand">Payroll System</div>
            <div class="jp-loader-bar"><span></span></div>
            <div class="jp-loader-text">Loading<b>.</b><b>.</b><b>.</b></div>
        </div>
    </div> -->

    <!-- Begin page -->
    <div id="layout-wrapper">
        <header id="page-topbar">
            <div class="layout-width">
                <div class="navbar-header">
                    <div class="d-flex">
                        <!-- LOGO -->
                        <div class="navbar-brand-box horizontal-logo">
                            <a href="index.php" class="logo logo-dark">
                                <span class="logo-sm">
                                    <img src="assets2/images/pwa/icon-192.png" alt="COMC" class="brand-mark">
                                </span>
                                <span class="logo-lg">
                                    <img src="assets2/images/pwa/icon-192.png" alt="COMC" class="brand-mark">Payroll System
                                </span>
                            </a>

                            <a href="index.php" class="logo logo-light">
                                <span class="logo-sm">
                                    <img src="assets2/images/pwa/icon-192.png" alt="COMC" class="brand-mark">
                                </span>
                                <span class="logo-lg">
                                    <img src="assets2/images/pwa/icon-192.png" alt="COMC" class="brand-mark">Payroll System
                                </span>
                            </a>
                        </div>

                        <button type="button" class="btn btn-sm px-3 fs-16 header-item vertical-menu-btn topnav-hamburger material-shadow-none" id="topnav-hamburger-icon">
                            <span class="hamburger-icon">
                                <span></span>
                                <span></span>
                                <span></span>
                            </span>
                        </button>

                        <!-- App Search-->

                    </div>

                    <div class="d-flex align-items-center">





                        <!-- <div class="dropdown topbar-head-dropdown ms-1 header-item">
                            <button type="button" class="btn btn-icon btn-topbar material-shadow-none btn-ghost-secondary rounded-circle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class='bx bx-category-alt fs-22'></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-lg p-0 dropdown-menu-end">
                                <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <h6 class="m-0 fw-semibold fs-15"> Web Apps </h6>
                                        </div>
                                        <div class="col-auto">
                                            <a href="#!" class="btn btn-sm btn-soft-info"> View All Apps
                                                <i class="ri-arrow-right-s-line align-middle"></i></a>
                                        </div>
                                    </div>
                                </div>

                                <div class="p-2">
                                    <div class="row g-0">
                                        <div class="col">
                                            <a class="dropdown-icon-item" href="#!">
                                                <img src="assets/images/brands/github.png" alt="Github">
                                                <span>GitHub</span>
                                            </a>
                                        </div>
                                        <div class="col">
                                            <a class="dropdown-icon-item" href="#!">
                                                <img src="assets/images/brands/bitbucket.png" alt="bitbucket">
                                                <span>Bitbucket</span>
                                            </a>
                                        </div>
                                        <div class="col">
                                            <a class="dropdown-icon-item" href="#!">
                                                <img src="assets/images/brands/dribbble.png" alt="dribbble">
                                                <span>Dribbble</span>
                                            </a>
                                        </div>
                                    </div>

                                    <div class="row g-0">
                                        <div class="col">
                                            <a class="dropdown-icon-item" href="#!">
                                                <img src="assets/images/brands/dropbox.png" alt="dropbox">
                                                <span>Dropbox</span>
                                            </a>
                                        </div>
                                        <div class="col">
                                            <a class="dropdown-icon-item" href="#!">
                                                <img src="assets/images/brands/mail_chimp.png" alt="mail_chimp">
                                                <span>Mail Chimp</span>
                                            </a>
                                        </div>
                                        <div class="col">
                                            <a class="dropdown-icon-item" href="#!">
                                                <img src="assets/images/brands/slack.png" alt="slack">
                                                <span>Slack</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div> -->
                        <!-- 
                        <div class="dropdown topbar-head-dropdown ms-1 header-item">
                            <button type="button" class="btn btn-icon btn-topbar material-shadow-none btn-ghost-secondary rounded-circle" id="page-header-cart-dropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-haspopup="true" aria-expanded="false">
                                <i class='bx bx-shopping-bag fs-22'></i>
                                <span class="position-absolute topbar-badge cartitem-badge fs-10 translate-middle badge rounded-pill bg-info">5</span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-xl dropdown-menu-end p-0 dropdown-menu-cart" aria-labelledby="page-header-cart-dropdown">
                                <div class="p-3 border-top-0 border-start-0 border-end-0 border-dashed border">
                                    <div class="row align-items-center">
                                        <div class="col">
                                            <h6 class="m-0 fs-16 fw-semibold"> My Cart</h6>
                                        </div>
                                        <div class="col-auto">
                                            <span class="badge bg-warning-subtle text-warning fs-13"><span class="cartitem-badge">7</span>
                                                items</span>
                                        </div>
                                    </div>
                                </div>
                                <div data-simplebar style="max-height: 300px;">
                                    <div class="p-2">
                                        <div class="text-center empty-cart" id="empty-cart">
                                            <div class="avatar-md mx-auto my-3">
                                                <div class="avatar-title bg-info-subtle text-info fs-36 rounded-circle">
                                                    <i class='bx bx-cart'></i>
                                                </div>
                                            </div>
                                            <h5 class="mb-3">Your Cart is Empty!</h5>
                                            <a href="apps-ecommerce-products.html" class="btn btn-success w-md mb-3">Shop Now</a>
                                        </div>
                                        <div class="d-block dropdown-item dropdown-item-cart text-wrap px-3 py-2">
                                            <div class="d-flex align-items-center">
                                                <img src="assets/images/products/img-1.png" class="me-3 rounded-circle avatar-sm p-2 bg-light" alt="user-pic">
                                                <div class="flex-grow-1">
                                                    <h6 class="mt-0 mb-1 fs-14">
                                                        <a href="apps-ecommerce-product-details.html" class="text-reset">Branded
                                                            T-Shirts</a>
                                                    </h6>
                                                    <p class="mb-0 fs-12 text-muted">
                                                        Quantity: <span>10 x $32</span>
                                                    </p>
                                                </div>
                                                <div class="px-2">
                                                    <h5 class="m-0 fw-normal">$<span class="cart-item-price">320</span></h5>
                                                </div>
                                                <div class="ps-2">
                                                    <button type="button" class="btn btn-icon btn-sm btn-ghost-secondary remove-item-btn"><i class="ri-close-fill fs-16"></i></button>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-block dropdown-item dropdown-item-cart text-wrap px-3 py-2">
                                            <div class="d-flex align-items-center">
                                                <img src="assets/images/products/img-2.png" class="me-3 rounded-circle avatar-sm p-2 bg-light" alt="user-pic">
                                                <div class="flex-grow-1">
                                                    <h6 class="mt-0 mb-1 fs-14">
                                                        <a href="apps-ecommerce-product-details.html" class="text-reset">Bentwood Chair</a>
                                                    </h6>
                                                    <p class="mb-0 fs-12 text-muted">
                                                        Quantity: <span>5 x $18</span>
                                                    </p>
                                                </div>
                                                <div class="px-2">
                                                    <h5 class="m-0 fw-normal">$<span class="cart-item-price">89</span></h5>
                                                </div>
                                                <div class="ps-2">
                                                    <button type="button" class="btn btn-icon btn-sm btn-ghost-secondary remove-item-btn"><i class="ri-close-fill fs-16"></i></button>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-block dropdown-item dropdown-item-cart text-wrap px-3 py-2">
                                            <div class="d-flex align-items-center">
                                                <img src="assets/images/products/img-3.png" class="me-3 rounded-circle avatar-sm p-2 bg-light" alt="user-pic">
                                                <div class="flex-grow-1">
                                                    <h6 class="mt-0 mb-1 fs-14">
                                                        <a href="apps-ecommerce-product-details.html" class="text-reset">
                                                            Borosil Paper Cup</a>
                                                    </h6>
                                                    <p class="mb-0 fs-12 text-muted">
                                                        Quantity: <span>3 x $250</span>
                                                    </p>
                                                </div>
                                                <div class="px-2">
                                                    <h5 class="m-0 fw-normal">$<span class="cart-item-price">750</span></h5>
                                                </div>
                                                <div class="ps-2">
                                                    <button type="button" class="btn btn-icon btn-sm btn-ghost-secondary remove-item-btn"><i class="ri-close-fill fs-16"></i></button>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-block dropdown-item dropdown-item-cart text-wrap px-3 py-2">
                                            <div class="d-flex align-items-center">
                                                <img src="assets/images/products/img-6.png" class="me-3 rounded-circle avatar-sm p-2 bg-light" alt="user-pic">
                                                <div class="flex-grow-1">
                                                    <h6 class="mt-0 mb-1 fs-14">
                                                        <a href="apps-ecommerce-product-details.html" class="text-reset">Gray
                                                            Styled T-Shirt</a>
                                                    </h6>
                                                    <p class="mb-0 fs-12 text-muted">
                                                        Quantity: <span>1 x $1250</span>
                                                    </p>
                                                </div>
                                                <div class="px-2">
                                                    <h5 class="m-0 fw-normal">$ <span class="cart-item-price">1250</span></h5>
                                                </div>
                                                <div class="ps-2">
                                                    <button type="button" class="btn btn-icon btn-sm btn-ghost-secondary remove-item-btn"><i class="ri-close-fill fs-16"></i></button>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-block dropdown-item dropdown-item-cart text-wrap px-3 py-2">
                                            <div class="d-flex align-items-center">
                                                <img src="assets/images/products/img-5.png" class="me-3 rounded-circle avatar-sm p-2 bg-light" alt="user-pic">
                                                <div class="flex-grow-1">
                                                    <h6 class="mt-0 mb-1 fs-14">
                                                        <a href="apps-ecommerce-product-details.html" class="text-reset">Stillbird Helmet</a>
                                                    </h6>
                                                    <p class="mb-0 fs-12 text-muted">
                                                        Quantity: <span>2 x $495</span>
                                                    </p>
                                                </div>
                                                <div class="px-2">
                                                    <h5 class="m-0 fw-normal">$<span class="cart-item-price">990</span></h5>
                                                </div>
                                                <div class="ps-2">
                                                    <button type="button" class="btn btn-icon btn-sm btn-ghost-secondary remove-item-btn"><i class="ri-close-fill fs-16"></i></button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-3 border-bottom-0 border-start-0 border-end-0 border-dashed border" id="checkout-elem">
                                    <div class="d-flex justify-content-between align-items-center pb-3">
                                        <h5 class="m-0 text-muted">Total:</h5>
                                        <div class="px-2">
                                            <h5 class="m-0" id="cart-item-total">$1258.58</h5>
                                        </div>
                                    </div>

                                    <a href="apps-ecommerce-checkout.html" class="btn btn-success text-center w-100">
                                        Checkout
                                    </a>
                                </div>
                            </div>
                        </div> -->

                        <!-- Notifications -->
                        <div class="dropdown topbar-head-dropdown ms-1 header-item" id="notificationDropdown">
                            <button type="button" class="btn btn-icon btn-topbar material-shadow-none btn-ghost-secondary rounded-circle" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-haspopup="true" aria-expanded="false">
                                <i class='bx bx-bell fs-22'></i>
                                <span class="position-absolute topbar-badge fs-10 translate-middle badge rounded-pill bg-danger" id="notif-badge" style="display:none;">0</span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end p-0" aria-labelledby="page-header-notifications-dropdown" style="width:360px;">
                                <div class="dropdown-head bg-success bg-pattern rounded-top">
                                    <div class="p-3 d-flex align-items-center">
                                        <h6 class="m-0 fs-16 fw-semibold text-white flex-grow-1">Notifications</h6>
                                        <span class="badge bg-light text-body fs-13" id="notif-unread-label">0 New</span>
                                    </div>
                                </div>
                                <div style="max-height: 320px; overflow-y: auto;" id="notif-list">
                                    <div class="text-center p-4 text-muted"><i class="ri-loader-4-line ri-spin fs-22"></i></div>
                                </div>
                                <div class="p-2 border-top text-center">
                                    <button type="button" class="btn btn-sm btn-soft-success w-100" id="notif-mark-all">
                                        <i class="ri-check-double-line me-1"></i>Mark all as read
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="ms-1 header-item d-none d-sm-flex">
                            <button type="button" class="btn btn-icon btn-topbar material-shadow-none btn-ghost-secondary rounded-circle" data-toggle="fullscreen">
                                <i class='bx bx-fullscreen fs-22'></i>
                            </button>
                        </div>

                        <!-- <div class="ms-1 header-item d-none d-sm-flex">
                            <button type="button" class="btn btn-icon btn-topbar material-shadow-none btn-ghost-secondary rounded-circle light-dark-mode">
                                <i class='bx bx-moon fs-22'></i>
                            </button>
                        </div> -->

                        <!-- <div class="dropdown topbar-head-dropdown ms-1 header-item" id="notificationDropdown">
                            <button type="button" class="btn btn-icon btn-topbar material-shadow-none btn-ghost-secondary rounded-circle" id="page-header-notifications-dropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-haspopup="true" aria-expanded="false">
                                <i class='bx bx-bell fs-22'></i>
                                <span class="position-absolute topbar-badge fs-10 translate-middle badge rounded-pill bg-danger">3<span class="visually-hidden">unread messages</span></span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end p-0" aria-labelledby="page-header-notifications-dropdown">

                                <div class="dropdown-head bg-primary bg-pattern rounded-top">
                                    <div class="p-3">
                                        <div class="row align-items-center">
                                            <div class="col">
                                                <h6 class="m-0 fs-16 fw-semibold text-white"> Notifications </h6>
                                            </div>
                                            <div class="col-auto dropdown-tabs">
                                                <span class="badge bg-light text-body fs-13"> 4 New</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="px-2 pt-2">
                                        <ul class="nav nav-tabs dropdown-tabs nav-tabs-custom" data-dropdown-tabs="true" id="notificationItemsTab" role="tablist">
                                            <li class="nav-item waves-effect waves-light">
                                                <a class="nav-link active" data-bs-toggle="tab" href="#all-noti-tab" role="tab" aria-selected="true">
                                                    All (4)
                                                </a>
                                            </li>
                                            <li class="nav-item waves-effect waves-light">
                                                <a class="nav-link" data-bs-toggle="tab" href="#messages-tab" role="tab" aria-selected="false">
                                                    Messages
                                                </a>
                                            </li>
                                            <li class="nav-item waves-effect waves-light">
                                                <a class="nav-link" data-bs-toggle="tab" href="#alerts-tab" role="tab" aria-selected="false">
                                                    Alerts
                                                </a>
                                            </li>
                                        </ul>
                                    </div>

                                </div>

                                <div class="tab-content position-relative" id="notificationItemsTabContent">
                                    <div class="tab-pane fade show active py-2 ps-2" id="all-noti-tab" role="tabpanel">
                                        <div data-simplebar style="max-height: 300px;" class="pe-2">
                                            <div class="text-reset notification-item d-block dropdown-item position-relative">
                                                <div class="d-flex">
                                                    <div class="avatar-xs me-3 flex-shrink-0">
                                                        <span class="avatar-title bg-info-subtle text-info rounded-circle fs-16">
                                                            <i class="bx bx-badge-check"></i>
                                                        </span>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <a href="#!" class="stretched-link">
                                                            <h6 class="mt-0 mb-2 lh-base">Your <b>Elite</b> author Graphic
                                                                Optimization <span class="text-secondary">reward</span> is
                                                                ready!
                                                            </h6>
                                                        </a>
                                                        <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                            <span><i class="mdi mdi-clock-outline"></i> Just 30 sec ago</span>
                                                        </p>
                                                    </div>
                                                    <div class="px-2 fs-15">
                                                        <div class="form-check notification-check">
                                                            <input class="form-check-input" type="checkbox" value="" id="all-notification-check01">
                                                            <label class="form-check-label" for="all-notification-check01"></label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="text-reset notification-item d-block dropdown-item position-relative">
                                                <div class="d-flex">
                                                    <img src="assets/images/users/avatar-2.jpg" class="me-3 rounded-circle avatar-xs flex-shrink-0" alt="user-pic">
                                                    <div class="flex-grow-1">
                                                        <a href="#!" class="stretched-link">
                                                            <h6 class="mt-0 mb-1 fs-13 fw-semibold">Angela Bernier</h6>
                                                        </a>
                                                        <div class="fs-13 text-muted">
                                                            <p class="mb-1">Answered to your comment on the cash flow forecast's
                                                                graph 🔔.</p>
                                                        </div>
                                                        <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                            <span><i class="mdi mdi-clock-outline"></i> 48 min ago</span>
                                                        </p>
                                                    </div>
                                                    <div class="px-2 fs-15">
                                                        <div class="form-check notification-check">
                                                            <input class="form-check-input" type="checkbox" value="" id="all-notification-check02">
                                                            <label class="form-check-label" for="all-notification-check02"></label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="text-reset notification-item d-block dropdown-item position-relative">
                                                <div class="d-flex">
                                                    <div class="avatar-xs me-3 flex-shrink-0">
                                                        <span class="avatar-title bg-danger-subtle text-danger rounded-circle fs-16">
                                                            <i class='bx bx-message-square-dots'></i>
                                                        </span>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <a href="#!" class="stretched-link">
                                                            <h6 class="mt-0 mb-2 fs-13 lh-base">You have received <b class="text-success">20</b> new messages in the conversation
                                                            </h6>
                                                        </a>
                                                        <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                            <span><i class="mdi mdi-clock-outline"></i> 2 hrs ago</span>
                                                        </p>
                                                    </div>
                                                    <div class="px-2 fs-15">
                                                        <div class="form-check notification-check">
                                                            <input class="form-check-input" type="checkbox" value="" id="all-notification-check03">
                                                            <label class="form-check-label" for="all-notification-check03"></label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="text-reset notification-item d-block dropdown-item position-relative">
                                                <div class="d-flex">
                                                    <img src="assets/images/users/avatar-8.jpg" class="me-3 rounded-circle avatar-xs flex-shrink-0" alt="user-pic">
                                                    <div class="flex-grow-1">
                                                        <a href="#!" class="stretched-link">
                                                            <h6 class="mt-0 mb-1 fs-13 fw-semibold">Maureen Gibson</h6>
                                                        </a>
                                                        <div class="fs-13 text-muted">
                                                            <p class="mb-1">We talked about a project on linkedin.</p>
                                                        </div>
                                                        <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                            <span><i class="mdi mdi-clock-outline"></i> 4 hrs ago</span>
                                                        </p>
                                                    </div>
                                                    <div class="px-2 fs-15">
                                                        <div class="form-check notification-check">
                                                            <input class="form-check-input" type="checkbox" value="" id="all-notification-check04">
                                                            <label class="form-check-label" for="all-notification-check04"></label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="my-3 text-center view-all">
                                                <button type="button" class="btn btn-soft-success waves-effect waves-light">View
                                                    All Notifications <i class="ri-arrow-right-line align-middle"></i></button>
                                            </div>
                                        </div>

                                    </div>

                                    <div class="tab-pane fade py-2 ps-2" id="messages-tab" role="tabpanel" aria-labelledby="messages-tab">
                                        <div data-simplebar style="max-height: 300px;" class="pe-2">
                                            <div class="text-reset notification-item d-block dropdown-item">
                                                <div class="d-flex">
                                                    <img src="assets/images/users/avatar-3.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                                    <div class="flex-grow-1">
                                                        <a href="#!" class="stretched-link">
                                                            <h6 class="mt-0 mb-1 fs-13 fw-semibold">James Lemire</h6>
                                                        </a>
                                                        <div class="fs-13 text-muted">
                                                            <p class="mb-1">We talked about a project on linkedin.</p>
                                                        </div>
                                                        <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                            <span><i class="mdi mdi-clock-outline"></i> 30 min ago</span>
                                                        </p>
                                                    </div>
                                                    <div class="px-2 fs-15">
                                                        <div class="form-check notification-check">
                                                            <input class="form-check-input" type="checkbox" value="" id="messages-notification-check01">
                                                            <label class="form-check-label" for="messages-notification-check01"></label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="text-reset notification-item d-block dropdown-item">
                                                <div class="d-flex">
                                                    <img src="assets/images/users/avatar-2.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                                    <div class="flex-grow-1">
                                                        <a href="#!" class="stretched-link">
                                                            <h6 class="mt-0 mb-1 fs-13 fw-semibold">Angela Bernier</h6>
                                                        </a>
                                                        <div class="fs-13 text-muted">
                                                            <p class="mb-1">Answered to your comment on the cash flow forecast's
                                                                graph 🔔.</p>
                                                        </div>
                                                        <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                            <span><i class="mdi mdi-clock-outline"></i> 2 hrs ago</span>
                                                        </p>
                                                    </div>
                                                    <div class="px-2 fs-15">
                                                        <div class="form-check notification-check">
                                                            <input class="form-check-input" type="checkbox" value="" id="messages-notification-check02">
                                                            <label class="form-check-label" for="messages-notification-check02"></label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="text-reset notification-item d-block dropdown-item">
                                                <div class="d-flex">
                                                    <img src="assets/images/users/avatar-6.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                                    <div class="flex-grow-1">
                                                        <a href="#!" class="stretched-link">
                                                            <h6 class="mt-0 mb-1 fs-13 fw-semibold">Kenneth Brown</h6>
                                                        </a>
                                                        <div class="fs-13 text-muted">
                                                            <p class="mb-1">Mentionned you in his comment on 📃 invoice #12501.
                                                            </p>
                                                        </div>
                                                        <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                            <span><i class="mdi mdi-clock-outline"></i> 10 hrs ago</span>
                                                        </p>
                                                    </div>
                                                    <div class="px-2 fs-15">
                                                        <div class="form-check notification-check">
                                                            <input class="form-check-input" type="checkbox" value="" id="messages-notification-check03">
                                                            <label class="form-check-label" for="messages-notification-check03"></label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="text-reset notification-item d-block dropdown-item">
                                                <div class="d-flex">
                                                    <img src="assets/images/users/avatar-8.jpg" class="me-3 rounded-circle avatar-xs" alt="user-pic">
                                                    <div class="flex-grow-1">
                                                        <a href="#!" class="stretched-link">
                                                            <h6 class="mt-0 mb-1 fs-13 fw-semibold">Maureen Gibson</h6>
                                                        </a>
                                                        <div class="fs-13 text-muted">
                                                            <p class="mb-1">We talked about a project on linkedin.</p>
                                                        </div>
                                                        <p class="mb-0 fs-11 fw-medium text-uppercase text-muted">
                                                            <span><i class="mdi mdi-clock-outline"></i> 3 days ago</span>
                                                        </p>
                                                    </div>
                                                    <div class="px-2 fs-15">
                                                        <div class="form-check notification-check">
                                                            <input class="form-check-input" type="checkbox" value="" id="messages-notification-check04">
                                                            <label class="form-check-label" for="messages-notification-check04"></label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="my-3 text-center view-all">
                                                <button type="button" class="btn btn-soft-success waves-effect waves-light">View
                                                    All Messages <i class="ri-arrow-right-line align-middle"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade p-4" id="alerts-tab" role="tabpanel" aria-labelledby="alerts-tab"></div>

                                    <div class="notification-actions" id="notification-actions">
                                        <div class="d-flex text-muted justify-content-center">
                                            Select <div id="select-content" class="text-body fw-semibold px-1">0</div> Result <button type="button" class="btn btn-link link-danger p-0 ms-3" data-bs-toggle="modal" data-bs-target="#removeNotificationModal">Remove</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div> -->

                        <div class="dropdown ms-sm-3 header-item topbar-user">
                            <button type="button" class="btn material-shadow-none" id="page-header-user-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="d-flex align-items-center">
                                    <img class="rounded-circle header-profile-user" src="assets/images/users/user-dummy-img.jpg" alt="Header Avatar">
                                    <span class="text-start ms-xl-2">
                                        <span class="d-none d-xl-inline-block ms-1 fw-medium user-name-text"><?= $_SESSION['login_name'] ?></span>
                                        <span class="d-none d-xl-block ms-1 fs-12 user-name-sub-text"><?= getRole($_SESSION['login_role']) ?></span>
                                    </span>
                                </span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <!-- item-->
                                <h6 class="dropdown-header">Welcome <?= $_SESSION['login_name'] ?>!</h6>
                                <a class="dropdown-item" href="app" id="try-v2-ui"><i class="mdi mdi-palette-outline text-muted fs-16 align-middle me-1"></i> <span class="align-middle">Try the new interface</span></a>
                                <script>document.getElementById('try-v2-ui').addEventListener('click', function (e) { e.preventDefault(); var fd = new FormData(); fd.append('settings', JSON.stringify({ ui: 'v2' })); fetch('ajax.php?action=save_ui_settings', { method: 'POST', body: fd, credentials: 'same-origin' }).finally(function () { location.href = 'app/' + (new URLSearchParams(location.search).get('page') || ''); }); });</script>
                                <a class="dropdown-item" href="index.php?page=profile"><i class="mdi mdi-account-circle text-muted fs-16 align-middle me-1"></i> <span class="align-middle">Profile</span></a>
                                <!-- Revealed by assets2/js/pwa-install.js once the browser offers an
                                     install (or on iOS, where it opens the manual steps instead). -->
                                <a class="dropdown-item" href="#" data-pwa-install data-pwa-display="block" style="display:none;"><i class="mdi mdi-cellphone-arrow-down text-muted fs-16 align-middle me-1"></i> <span class="align-middle">Install App</span></a>
                                <!-- <a class="dropdown-item" href="apps-chat.html"><i class="mdi mdi-message-text-outline text-muted fs-16 align-middle me-1"></i> <span class="align-middle">Messages</span></a>
                                <a class="dropdown-item" href="apps-tasks-kanban.html"><i class="mdi mdi-calendar-check-outline text-muted fs-16 align-middle me-1"></i> <span class="align-middle">Taskboard</span></a>
                                <a class="dropdown-item" href="pages-faqs.html"><i class="mdi mdi-lifebuoy text-muted fs-16 align-middle me-1"></i> <span class="align-middle">Help</span></a>
                                <div class="dropdown-divider"></div> -->
                                <!-- <a class="dropdown-item" href="pages-profile.html"><i class="mdi mdi-wallet text-muted fs-16 align-middle me-1"></i> <span class="align-middle">Balance : <b>$5971.67</b></span></a>
                                <a class="dropdown-item" href="pages-profile-settings.html"><span class="badge bg-success-subtle text-success mt-1 float-end">New</span><i class="mdi mdi-cog-outline text-muted fs-16 align-middle me-1"></i> <span class="align-middle">Settings</span></a>
                                <a class="dropdown-item" href="auth-lockscreen-basic.html"><i class="mdi mdi-lock text-muted fs-16 align-middle me-1"></i> <span class="align-middle">Lock screen</span></a> -->
                                <a class="dropdown-item" href="ajax.php?action=logout"><i class="mdi mdi-logout text-muted fs-16 align-middle me-1"></i> <span class="align-middle" data-key="t-logout">Logout</span></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- removeNotificationModal -->
        <div id="removeNotificationModal" class="modal fade zoomIn" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="NotificationModalbtn-close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mt-2 text-center">
                            <lord-icon src="https://cdn.lordicon.com/gsqxdxog.json" trigger="loop" colors="primary:#f7b84b,secondary:#f06548" style="width:100px;height:100px"></lord-icon>
                            <div class="mt-4 pt-2 fs-15 mx-4 mx-sm-5">
                                <h4>Are you sure ?</h4>
                                <p class="text-muted mx-4 mb-0">Are you sure you want to remove this Notification ?</p>
                            </div>
                        </div>
                        <div class="d-flex gap-2 justify-content-center mt-4 mb-2">
                            <button type="button" class="btn w-sm btn-light" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="btn w-sm btn-danger" id="delete-notification">Yes, Delete It!</button>
                        </div>
                    </div>

                </div><!-- /.modal-content -->
            </div><!-- /.modal-dialog -->
        </div><!-- /.modal -->
        <!-- ========== App Menu ========== -->
        <?php include 'includes/navbar.php' ?>
        <!-- Left Sidebar End -->
        <!-- Vertical Overlay-->
        <div class="vertical-overlay"></div>

        <!-- ============================================================== -->
        <!-- Start right Content here -->
        <!-- ============================================================== -->
        <div id="main-content">
            <?php
            $routes = [
                'home'                 => 'home',
                'employee'             => 'employee',
                'employee-details'     => 'employee-details',
                'payroll'              => 'payroll-list',
                'payroll_calculations' => 'payroll_calculations',
                'dtr'                  => 'dtr',
                'dtr-details'          => 'dtr-details',
                'attendance'           => 'attendance',
                'sites'                => 'sites',
                'clusters'             => 'clusters',
                'position'             => 'position',
                'users'                => 'users',
                'visitors-logs'        => 'visitors-logs',
                'profile'              => 'profile',
                'allowances'           => 'allowances',
                'contributions'        => 'contributions',
                'deductions'           => 'deductions',
                'refunds'              => 'refunds',
                'payroll-report'       => 'payroll-report',
                'payroll_items'        => 'payroll_items',
                'time-logs'            => 'time-logs',
                'site_settings'        => 'site_settings',
                'department'           => 'department',
                'area'                 => 'area',
                'branch'               => 'branch',
                'compare-dtr'          => 'compare-dtr',
                'loans'                => 'loans',
                'payroll-comparison'   => 'payroll-comparison',
                'remittance-report'    => 'remittance-report',
                'reports'              => 'reports',
                'loan-deduction-ledger' => 'loan-deduction-ledger',
                'payroll-register'     => 'payroll-register',
                'deductions-report'    => 'deductions-report',
                'employee-masterlist'  => 'employee-masterlist',
                'attendance-summary'   => 'attendance-summary',
                'leave-dashboard'      => 'leave-dashboard',
                'leaves'               => 'leaves',
                'leave_types'          => 'leave_types',
                'leave_balances'       => 'leave_balances',
                'leave-balances-report' => 'leave-balances-report',
                'calendar'             => 'calendar',
                'work-schedules'       => 'work-schedules',
                'schedule-roster'      => 'schedule-roster',
                'daily-board'          => 'daily-board',
                'attendance-requests'  => 'attendance-requests',
                'biometric-dtr'        => 'biometric-dtr',
                'punch-logs'           => 'punch-logs',
                'similarity-reports'   => 'similarity-reports',
                'pin-attendance'       => 'pin-attendance',
                'kiosk-devices'        => 'kiosk-devices',
                'employee-201'         => 'employee-201',
                'hr-overview'          => 'hr-overview',
                'hr-announcements'     => 'hr-announcements',
                'hr-requests'          => 'hr-requests',
                'hr-offboarding'       => 'hr-offboarding',
                'pay-settings'         => 'pay-settings',
                'thirteenth-month'     => 'thirteenth-month',
                'bank-payout'          => 'bank-payout',
                'bir-alphalist'        => 'bir-alphalist',
            ];

            $page = isset($_GET['page']) ? trim($_GET['page']) : 'home';

            // 'payroll-list' is the URL the nav links use; every permission
            // check, route lookup and title lookup in the app still keys off
            // 'payroll', so normalize here rather than touching all of them.
            if ($page === 'payroll-list') $page = 'payroll';

            if (!array_key_exists($page, $routes)) {
                $page = 'home';
            }

            // ── The gate ────────────────────────────────────────────────
            // Every per-role rule lives in page_allowed() (db_connect.php):
            // the offline payroll box, the admin-only payroll/DTR screens, the
            // Timekeeper's two pages, the leave-only slice for Supervisor /
            // Department Head, and HR's people-operations slice. A blocked
            // request lands on that role's own home screen. This is the
            // server-side boundary — hiding a menu item never stopped a typed
            // URL, and the sidebar now asks this same function.
            $tk = is_timekeeper($login_role);

            if (!page_allowed($page, $login_role)) {
                $page = role_landing_page($login_role);
            }

            if (!page_allowed($page, $login_role)) {
                // Even the landing screen is closed to this role — say so
                // rather than including a page they may not see.
                echo '<div class="container-fluid mt-4"><div class="alert alert-warning">'
                   . '<i class="ri-lock-line me-1"></i>Your role does not have access to this screen.'
                   . '</div></div>';
            } elseif ($tk && $page === 'employee-details') {
                include 'employee-details-timekeeper.php';
            } else {
                include $routes[$page] . '.php';
            }
            ?>
        </div>

        <?php
        // Shared employee quick-view drawer — any element on any routed page
        // with data-emp-quickview="<employee id>" opens it. Replaces the old
        // assets2/js/employee-view.js drawer that header.php used to load.
        include 'component/employee_quick_view.php';
        ?>

        <footer class="footer">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6">
                        <script>
                            document.write(new Date().getFullYear());
                        </script>
                        © COMC.
                    </div>

                </div>
            </div>
        </footer>

        <!-- end main content-->

    </div>
    <!-- END layout-wrapper -->



    <!--start back-to-top-->
    <!-- <button onclick="topFunction()" class="btn btn-danger btn-icon" id="back-to-top">
        <i class="ri-arrow-up-line"></i>
    </button> -->
    <!--end back-to-top-->



    <!-- Theme Settings -->



    <?php include __DIR__ . '/includes/app_scripts.php'; ?>
    <!-- <script src="assets2/vendor/select2/select2.min.js"></script> -->

    <?php include __DIR__ . '/includes/page_scripts.php'; ?>

    <!-- page loader: hide after full page load -->
    <script>
        (function() {
            var loader = document.getElementById('jp-loader');
            if (!loader) return;
            var gone = false;

            function hideLoader() {
                if (gone) return;
                gone = true;
                loader.classList.add('jp-loader-hidden');
                setTimeout(function() {
                    loader.style.display = 'none';
                }, 450);
            }

            function delayedHide() {
                setTimeout(hideLoader, 1000);
            }
            if (document.readyState === 'complete') {
                delayedHide();
            } else {
                window.addEventListener('load', delayedHide);
            }
        })();
    </script>


</body>

</html>