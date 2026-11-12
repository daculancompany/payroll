<?php
/* v2 settings modal: left nav with search, content pane, footer actions.
 * The controls are wired up by assets3/js/settings-modal.js; swatch grids
 * are filled from the ColorThemes registry there. */
$__sections = [
    'Appearance' => [
        ['theme',   'Theme',          'ri-paint-brush-line', 'light dark black sync device color colour gradient sidebar preview'],
        ['accent',  'Accent Color',   'ri-flashlight-line',  'accent brand primary button link highlight'],
        ['display', 'Text & Display', 'ri-text',             'font size density corners radius header document paper white payslip'],
    ],
    'Accessibility' => [
        ['access',  'Accessibility',  'ri-eye-line',         'contrast compact spacing motion animation brand gradient'],
    ],
    'About' => [
        ['privacy', 'Privacy',        'ri-shield-check-line','privacy data security ra 10173 session'],
        ['about',   'Interface',      'ri-information-line', 'about version classic new ui shell switch'],
    ],
];
?>
<div class="modal fade v2-settings" id="v2-settings" tabindex="-1" aria-labelledby="v2-settings-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="ps">
                <aside class="ps-nav">
                    <div class="ps-nav__scroll">
                        <?php /* Where "Edit profile" goes: the admin profile page, or the
                                 portal's My Info tab ($V2_PS_PROFILE_JS set by the portal). */ ?>
                        <?php if (!empty($V2_PS_PROFILE_JS)): ?>
                        <a href="javascript:void(0)" class="ps-nav__profile" data-bs-dismiss="modal" onclick="<?= e($V2_PS_PROFILE_JS) ?>">
                        <?php else: ?>
                        <a href="app/profile" class="ps-nav__profile" data-nav data-bs-dismiss="modal">
                        <?php endif; ?>
                            <span class="v2-avatar v2-avatar--lg"><?= e(v2_initials($user_name)) ?></span>
                            <span class="ps-nav__profile-copy">
                                <strong><?= e($user_name) ?></strong>
                                <span>Edit profile <i class="ri-pencil-line"></i></span>
                            </span>
                        </a>
                        <label class="ps-nav__search">
                            <i class="ri-search-line"></i>
                            <input type="search" placeholder="Search" data-ps-search aria-label="Search settings">
                        </label>
                        <?php foreach ($__sections as $__g => $__items): ?>
                            <div class="ps-nav__group" data-ps-group>
                                <div class="ps-nav__group-title"><?= e($__g) ?></div>
                                <?php foreach ($__items as $__it): ?>
                                    <button type="button" class="ps-nav__item" data-ps-tab="<?= e($__it[0]) ?>" data-ps-keywords="<?= e(strtolower($__it[1] . ' ' . $__it[3])) ?>">
                                        <i class="<?= e($__it[2]) ?>"></i><span><?= e($__it[1]) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="ps-nav__empty" data-ps-empty hidden>No matching settings</div>
                    </div>
                    <div class="ps-nav__foot"><i class="ri-cloud-line"></i><span>Changes apply instantly</span></div>
                </aside>

                <section class="ps-main">
                    <header class="ps-main__head">
                        <strong id="v2-settings-title" data-ps-title>Theme</strong>
                        <button type="button" class="v2-icon-btn" data-bs-dismiss="modal" aria-label="Close settings"><i class="ri-close-line"></i></button>
                    </header>

                    <div class="ps-main__body">
                        <!-- Theme -->
                        <div class="ps-pane" data-ps-pane="theme">
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Same as device theme</strong><span>Match your device's light or dark mode.</span></div>
                                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" data-ps="system"></div>
                            </div>
                            <div class="ps-label">Default themes</div>
                            <div class="ps-theme-tiles">
                                <button type="button" class="ps-theme-tile ps-theme-tile--light"  data-ps-theme="light"  title="Light"  aria-label="Light"></button>
                                <button type="button" class="ps-theme-tile ps-theme-tile--dark"   data-ps-theme="dark"   title="Dark"   aria-label="Dark"></button>
                                <button type="button" class="ps-theme-tile ps-theme-tile--black"  data-ps-theme="black"  title="Black"  aria-label="Black"></button>
                                <button type="button" class="ps-theme-tile ps-theme-tile--system" data-ps-theme="system" title="Sync with device" aria-label="Sync with device"><i class="ri-loop-right-line"></i></button>
                            </div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Dark sidebar</strong><span>Keep the sidebar dark in the light theme.</span></div>
                                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" data-ps="darkSidebar"></div>
                            </div>
                            <div class="ps-card">
                                <div class="ps-card__head">
                                    <div><strong>Color Themes</strong><span>Tint the whole app. Every surface layers over one gradient.</span></div>
                                    <button type="button" class="btn btn-light btn-sm" data-ps-preview>Preview Theme</button>
                                </div>
                                <div class="ps-swatch-row" data-ps-colorthemes></div>
                            </div>
                        </div>

                        <!-- Accent -->
                        <div class="ps-pane" data-ps-pane="accent" hidden>
                            <p class="ps-hint">Re-tints buttons, links, tabs and highlights across the app. Colour themes set their own accent; you can still override it here.</p>
                            <div class="ps-swatch-row" data-ps-accents></div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Custom colour</strong></div>
                                <div class="ps-inline">
                                    <input type="color" class="form-control form-control-color" data-ps="accentPicker" aria-label="Custom accent colour">
                                    <code data-ps-accent-hex></code>
                                    <button type="button" class="btn btn-light btn-sm" data-ps-accent-reset>Reset</button>
                                </div>
                            </div>
                        </div>

                        <!-- Text & Display -->
                        <div class="ps-pane" data-ps-pane="display" hidden>
                            <div class="ps-slider">
                                <div class="ps-slider__head"><strong>Interface size</strong><span data-ps-density-val>100%</span></div>
                                <input type="range" class="form-range" min="90" max="115" step="5" data-ps="density" aria-label="Interface size">
                            </div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Font</strong></div>
                                <select class="form-select ps-select" data-ps="fontFamily" data-no-cs>
                                    <option value="default">Inter (default)</option>
                                    <option value="system">System</option>
                                    <option value="rounded">Rounded</option>
                                    <option value="serif">Serif</option>
                                    <option value="mono">Mono</option>
                                </select>
                            </div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Corners</strong></div>
                                <div class="ps-seg" data-ps-seg="radius">
                                    <button type="button" data-v="sharp">Sharp</button><button type="button" data-v="default">Default</button><button type="button" data-v="rounded">Rounded</button>
                                </div>
                            </div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Plain white documents</strong><span>Payslips, computation sheets and DTR forms on pure white instead of a light tint of your theme.</span></div>
                                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" data-ps="paperWhite"></div>
                            </div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Header style</strong></div>
                                <div class="ps-seg" data-ps-seg="headerStyle">
                                    <button type="button" data-v="auto">Auto</button><button type="button" data-v="solid">Solid</button><button type="button" data-v="translucent">Glass</button>
                                </div>
                            </div>
                        </div>

                        <!-- Accessibility -->
                        <div class="ps-pane" data-ps-pane="access" hidden>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>High contrast</strong><span>Stronger borders and darker secondary text.</span></div>
                                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" data-ps="highContrast"></div>
                            </div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Compact spacing</strong><span>Tighter cards, tables and controls.</span></div>
                                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" data-ps="compact"></div>
                            </div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Reduce motion</strong><span>Turn off transitions and animations.</span></div>
                                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" data-ps="reduceMotion"></div>
                            </div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Brand accents</strong><span>Subtle accent gradient on primary buttons and headers.</span></div>
                                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" data-ps="brandGradient"></div>
                            </div>
                        </div>

                        <!-- Privacy -->
                        <div class="ps-pane" data-ps-pane="privacy" hidden>
                            <p class="ps-hint">This system holds personal and salary data of employees. It is handled under the Data Privacy Act of 2012 (RA 10173).</p>
                            <div class="ps-privacy">
                                <div class="ps-privacy__point"><strong>Role-based access</strong><span>Every screen and action is checked on the server against your role. Hiding a menu item is never the only safeguard.</span></div>
                                <div class="ps-privacy__point"><strong>Session protection</strong><span>Sessions use secure, HTTP-only cookies, a CSRF token on every change, and sign you out after a period of inactivity.</span></div>
                                <div class="ps-privacy__point"><strong>Your preferences</strong><span>Appearance settings are stored on this device and, when you choose "Save to account", on your user record. Nothing else is collected.</span></div>
                            </div>
                        </div>

                        <!-- Interface -->
                        <div class="ps-pane" data-ps-pane="about" hidden>
                            <?php if (!isset($V2_PS_SHELL) || $V2_PS_SHELL): ?>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Use the new interface by default</strong><span>When off, signing in opens the classic interface.</span></div>
                                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" data-ps-shell></div>
                            </div>
                            <?php endif; ?>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Version</strong><span>Build <?= e(defined('APP_VERSION') ? APP_VERSION : '') ?> · Interface v2</span></div>
                            </div>
                            <div class="ps-row">
                                <div class="ps-row__copy"><strong>Keyboard shortcuts</strong><span><kbd>⌘K</kbd> / <kbd>Ctrl K</kbd> search pages · <kbd>[</kbd> collapse sidebar</span></div>
                            </div>
                        </div>
                    </div>

                    <footer class="ps-main__foot">
                        <button type="button" class="btn btn-light" data-ps-reset><i class="ri-history-line me-1"></i> Reset to defaults</button>
                        <button type="button" class="btn btn-primary" data-ps-save>Save to account</button>
                    </footer>
                </section>
            </div>
        </div>
    </div>
</div>

<!-- Floating bar while previewing a colour theme -->
<div class="ps-preview-bar" id="ps-preview-bar" hidden>
    <button type="button" class="v2-icon-btn" data-ps-prev aria-label="Previous theme"><i class="ri-arrow-left-s-line"></i></button>
    <div class="ps-preview-bar__copy"><small>Previewing</small><strong data-ps-preview-name>Theme</strong></div>
    <button type="button" class="v2-icon-btn" data-ps-next aria-label="Next theme"><i class="ri-arrow-right-s-line"></i></button>
    <button type="button" class="btn btn-sm btn-light" data-ps-preview-cancel>Cancel</button>
    <button type="button" class="btn btn-sm btn-primary" data-ps-preview-apply>Apply</button>
</div>
