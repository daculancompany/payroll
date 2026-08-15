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
                'payroll'              => 'payroll',
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
                'pay-settings'         => 'pay-settings',
                'thirteenth-month'     => 'thirteenth-month',
                'bank-payout'          => 'bank-payout',
                'bir-alphalist'        => 'bir-alphalist',
            ];

            $page = isset($_GET['page']) ? trim($_GET['page']) : 'home';

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



    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.0/jquery.min.js" integrity="sha256-xNzN2a4ltkB44Mc/Jz3pT4iU1cmeR0FkXs4pru/JxaQ=" crossorigin="anonymous"></script>
    <!-- JAVASCRIPT -->
    <script src="assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="assets/libs/simplebar/simplebar.min.js"></script>
    <script src="assets/libs/node-waves/waves.min.js"></script>
    <script src="assets/libs/feather-icons/feather.min.js"></script>
    <script src="assets/js/pages/plugins/lord-icon-2.1.0.js"></script>
    <script src="assets/js/plugins.js"></script>
    <!-- App js -->
    <script src="assets/js/app.js"></script>
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

    <!-- <script src="assets2/vendor/select2/select2.min.js"></script> -->

    <?php if ($page == 'home') { ?>
        <script src="assets2/js/home.js"></script>
    <?php } ?>

    <?php if ($page == 'employee' || $page == 'employee-details') { ?>
        <script src="assets2/js/employee.js?v=9"></script>
    <?php } ?>
    <?php if ($page == 'schedule-roster') { ?>
        <script src="assets2/js/schedule-roster.js?v=1"></script>
    <?php } ?>
    <?php if ($page == 'daily-board') { ?>
        <script src="assets2/js/daily-board.js?v=2"></script>
    <?php } ?>
    <?php if ($page == 'department') { ?>
        <script src="assets2/js/department.js"></script>
    <?php } ?>
    <?php if ($page == 'branch') { ?>
        <script src="assets2/js/branch.js"></script>
    <?php } ?>
    <?php if ($page == 'position') { ?>
        <script src="assets2/js/position.js"></script>
    <?php } ?>
    <?php if ($page == 'deductions') { ?>
        <script src="assets2/js/deductions.js"></script>
    <?php } ?>
    <?php if ($page == 'payroll'  || $page == 'payroll_items') { ?>
        <script src="assets2/js/payroll.js"></script>
    <?php } ?>
    <?php if ($page == 'attendance') { ?>
        <script src="assets2/js/attendance.js"></script>
    <?php } ?>
    <?php if ($page == 'time-logs') { ?>
        <script src="assets2/js/time_logs.js"></script>
    <?php } ?>
    <?php if ($page == 'allowances') { ?>
        <script src="assets2/js/allowances.js"></script>
    <?php } ?>

    <?php if ($page == 'sites') { ?>
        <script src="assets2/js/sites.js"></script>
    <?php } ?>

    <?php if ($page == 'users') { ?>
        <script src="assets2/js/user.js"></script>
    <?php } ?>

    <?php if ($page == 'dtr') { ?>
        <script src="assets2/js/dtr.js"></script>
    <?php } ?>
    <?php if ($page == 'dtr-details') { ?>
        <script src="assets2/js/dtr-details.js"></script>
    <?php } ?>
    <?php if ($page == 'clusters') { ?>
        <script src="assets2/js/clusters.js"></script>
    <?php } ?>
    <?php if ($page == 'visitors-logs') { ?>
        <script src="assets2/js/logs.js"></script>
    <?php } ?>
    <?php if ($page == 'visitors-logs') { ?>
        <script src="assets2/js/visitors-logs.js"></script>
    <?php } ?>
    <?php if ($page == 'payroll_calculations') { ?>
        <script src="assets2/js/payroll_calculations.js"></script>
    <?php } ?>
    <?php if (in_array($page, ['payroll-report','loan-deduction-ledger','payroll-register','employee-masterlist','attendance-summary'])) { ?>
        <script src="assets2/js/reports.js"></script>
    <?php } ?>
    <?php if ($page == 'refunds') { ?>
        <script src="assets2/js/refunds.js"></script>
    <?php } ?>

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