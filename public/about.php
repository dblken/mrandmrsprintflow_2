<?php

/**

 * About Us Page

 * PrintFlow - Printing Shop PWA

 * Content managed via Admin > Settings > About Page

 */

require_once __DIR__ . '/../includes/auth.php';

redirect_admin_staff_from_public();



$page_title = 'About Us - PrintFlow';

$use_landing_css = true;

require_once __DIR__ . '/../includes/header.php';

require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/runtime_config.php';
require_once __DIR__ . '/../includes/team_photo_helper.php';



// Load about config
$about_cfg_path = __DIR__ . '/../public/assets/uploads/about_config.json';
$about_cfg = printflow_load_runtime_config('about', $about_cfg_path);

// Load shop config for name
$shop_cfg_path = __DIR__ . '/../public/assets/uploads/shop_config.json';
$shop_cfg = printflow_load_runtime_config('shop', $shop_cfg_path);

$shop_name = htmlspecialchars($shop_cfg['name'] ?? 'PrintFlow');



// Defaults

$tagline       = htmlspecialchars($about_cfg['tagline']       ?? 'Your Trusted Printing Partner Since Day One');

$hero_subtitle = htmlspecialchars($about_cfg['hero_subtitle'] ?? 'We bring creativity and color to life — from vibrant tarpaulins to precision stickers, custom apparel to large-format prints.');

$mission       = htmlspecialchars($about_cfg['mission']       ?? 'To provide exceptional printing solutions that empower businesses and individuals to communicate their message with clarity, creativity, and impact.');

$vision        = htmlspecialchars($about_cfg['vision']        ?? 'To be the most trusted printing partner in the region, known for quality, speed, and innovative print technology.');

$founding_year = htmlspecialchars($about_cfg['founding_year'] ?? '2018');

$team_size     = htmlspecialchars($about_cfg['team_size']     ?? '25+');

$projects_done = htmlspecialchars($about_cfg['projects_done'] ?? '10,000+');

$happy_clients = htmlspecialchars($about_cfg['happy_clients'] ?? '5,000+');

$values        = isset($about_cfg['values']) && is_array($about_cfg['values']) ? $about_cfg['values'] : [];

$team_members  = isset($about_cfg['team_members']) && is_array($about_cfg['team_members']) ? $about_cfg['team_members'] : [];



// Value icon SVGs

function about_icon(string $icon): string {

    return match ($icon) {

        'clock'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',

        'sparkle' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>',

        'heart'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>',

        default   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>',

    };

}

?>



<!-- ============================================================

     HERO

     ============================================================ -->

<section class="lp-mini-hero" style="padding-top:0; padding-bottom:5rem;">

    <?php $nav_header_class = 'lp-hero-nav sticky top-0 z-50'; require __DIR__ . '/../includes/nav-header.php'; ?>

    <div class="lp-mini-hero-inner" style="padding-top:4rem;">

        <div class="lp-wrap" style="text-align:center;">

            <p class="lp-hero-tag" style="margin-bottom:1.5rem;">✦ Our Story</p>

            <h1 style="font-size:clamp(2.2rem,5vw,3.5rem); font-weight:800; color:#fff; letter-spacing:-0.03em; margin-bottom:1.25rem; line-height:1.1;">

                <?php echo $tagline; ?>

            </h1>

            <p style="font-size:clamp(0.9rem,2.5vw,1.0625rem); color:var(--lp-muted); max-width:640px; margin:0 auto 2.5rem; line-height:1.7; padding:0 1rem;">

                <?php echo $hero_subtitle; ?>

            </p>

            <div class="hero-buttons" style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap; padding:0 1rem;">

                <a href="<?php echo $base_path; ?>/public/products.php" class="lp-btn lp-btn-primary">Browse Our Products</a>

                <a href="<?php echo $base_path; ?>/public/services.php" class="lp-btn lp-btn-outline">View Our Services</a>

            </div>

        </div>

    </div>

</section>



<!-- ============================================================

     STATS BAR

     ============================================================ -->

<section style="background:var(--lp-bg2); border-bottom:1px solid var(--lp-border); padding:3rem 0;">

    <div class="lp-wrap">

        <div class="stats-grid">

            <div>

                <div style="font-size:2.25rem; font-weight:800; color:var(--lp-accent); line-height:1;"><?php echo $founding_year; ?></div>

                <div style="font-size:0.85rem; color:var(--lp-muted); margin-top:0.5rem; text-transform:uppercase; letter-spacing:.06em;">Est. Year</div>

            </div>

            <div>

                <div style="font-size:2.25rem; font-weight:800; color:var(--lp-accent); line-height:1;"><?php echo $team_size; ?></div>

                <div style="font-size:0.85rem; color:var(--lp-muted); margin-top:0.5rem; text-transform:uppercase; letter-spacing:.06em;">Team Members</div>

            </div>

            <div>

                <div style="font-size:2.25rem; font-weight:800; color:var(--lp-accent); line-height:1;"><?php echo $projects_done; ?></div>

                <div style="font-size:0.85rem; color:var(--lp-muted); margin-top:0.5rem; text-transform:uppercase; letter-spacing:.06em;">Projects Done</div>

            </div>

            <div>

                <div style="font-size:2.25rem; font-weight:800; color:var(--lp-accent); line-height:1;"><?php echo $happy_clients; ?></div>

                <div style="font-size:0.85rem; color:var(--lp-muted); margin-top:0.5rem; text-transform:uppercase; letter-spacing:.06em;">Happy Clients</div>

            </div>

        </div>

    </div>

</section>



<!-- ============================================================

     MISSION & VISION

     ============================================================ -->

<section class="lp-section">

    <div class="lp-wrap">

        <div style="text-align:center; margin-bottom:3.5rem;">

            <p class="lp-heading-label">Who We Are</p>

            <h2 class="lp-heading">Purpose-Driven <span style="color:var(--lp-accent-l);">Printing</span></h2>

            <p class="lp-heading-desc">Our mission and vision guide every product we produce and every client we serve.</p>

        </div>



        <div class="mission-vision-grid">

            <!-- Mission -->

            <div style="background:var(--lp-surface); border:1px solid rgba(83,197,224,0.15); border-radius:1.25rem; padding:2.5rem; position:relative; overflow:hidden;">

                <div style="position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg, var(--lp-accent), var(--lp-accent-l));"></div>

                <div class="mv-card-header">
                    <div style="width:52px; height:52px; background:rgba(50,161,196,0.15); border-radius:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">

                        <svg style="width:26px; height:26px; color:var(--lp-accent-l);" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>

                        </svg>

                    </div>

                    <h3 style="font-size:1.4rem; font-weight:700; color:#fff;">Our Mission</h3>
                </div>

                <p style="color:var(--lp-muted); line-height:1.8; font-size:1rem;"><?php echo $mission; ?></p>

            </div>



            <!-- Vision -->

            <div style="background:var(--lp-surface); border:1px solid rgba(83,197,224,0.15); border-radius:1.25rem; padding:2.5rem; position:relative; overflow:hidden;">

                <div style="position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg, var(--lp-accent-l), #a3e8f7);"></div>

                <div class="mv-card-header">
                    <div style="width:52px; height:52px; background:rgba(83,197,224,0.15); border-radius:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">

                        <svg style="width:26px; height:26px; color:var(--lp-accent-l);" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>

                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>

                        </svg>

                    </div>

                    <h3 style="font-size:1.4rem; font-weight:700; color:#fff;">Our Vision</h3>
                </div>

                <p style="color:var(--lp-muted); line-height:1.8; font-size:1rem;"><?php echo $vision; ?></p>

            </div>

        </div>

    </div>

</section>



<!-- ============================================================

     CORE VALUES

     ============================================================ -->

<?php if (!empty($values)): ?>

<section class="lp-section-light">

    <div class="lp-wrap">

        <div style="text-align:center; margin-bottom:3.5rem;">

            <p style="font-size:0.8rem; font-weight:700; color:var(--lp-accent); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.75rem;">What Drives Us</p>

            <h2 style="font-size:clamp(1.9rem,4vw,2.8rem); font-weight:800; color:#fff; letter-spacing:-0.025em; margin-bottom:1rem;">Our Core <span style="color:var(--lp-accent);">Values</span></h2>

            <p style="font-size:1.0625rem; color:var(--lp-muted); max-width:520px; margin:0 auto; line-height:1.7;">The principles that guide every print, every project, every promise we make to you.</p>

        </div>



        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:1.5rem;">

            <?php foreach ($values as $v): ?>

            <div style="background:var(--lp-surface); border:1px solid rgba(83,197,224,0.15); border-radius:1.25rem; padding:2rem; box-shadow:0 2px 12px rgba(0,0,0,0.2); transition:transform .2s, box-shadow .2s;"

                onmouseover="this.style.transform='translateY(-4px)';this.style.boxShadow='0 8px 30px rgba(50,161,196,0.2)'"

                onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 2px 12px rgba(0,0,0,0.2)'">

                <div class="cv-card-header">
                    <div style="width:48px; height:48px; background:linear-gradient(135deg, #eaf7fb, #cff1f8); border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">

                        <svg style="width:24px; height:24px; color:var(--lp-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                            <?php echo about_icon($v['icon'] ?? 'star'); ?>

                        </svg>

                    </div>

                    <h3 style="font-size:1.0625rem; font-weight:700; color:#fff;"><?php echo htmlspecialchars($v['title']); ?></h3>
                </div>

                <p style="font-size:.9375rem; color:var(--lp-muted); line-height:1.6;"><?php echo htmlspecialchars($v['desc']); ?></p>

            </div>

            <?php endforeach; ?>

        </div>

    </div>

</section>

<?php endif; ?>



<!-- ============================================================

     TEAM

     ============================================================ -->

<?php if (!empty($team_members)): ?>

<section class="lp-section">

    <div class="lp-wrap">

        <div style="text-align:center; margin-bottom:3.5rem;">

            <p class="lp-heading-label">The People Behind the Prints</p>

            <h2 class="lp-heading">Meet Our <span style="color:var(--lp-accent-l);">Team</span></h2>

            <p class="lp-heading-desc">Passionate professionals dedicated to making your printing experience seamless and outstanding.</p>

        </div>



        <div class="team-grid" style="display:flex; flex-wrap:wrap; gap:1.75rem; justify-content:center;">

            <?php foreach ($team_members as $tm): ?>
            <?php $teamPhotoUrl = printflow_team_photo_public_url($tm['photo'] ?? ''); ?>
            <div style="text-align:center; max-width:240px; width:100%; margin:0;">

                <?php if ($teamPhotoUrl): ?>

                    <img src="<?php echo htmlspecialchars($teamPhotoUrl, ENT_QUOTES); ?>"

                         class="w-20 h-20 md:w-28 md:h-28 object-cover rounded-full border-3 border-[var(--lp-accent)] mx-auto mb-4"
                         style="width:100px; height:100px; border-radius:50%; object-fit:cover; border:3px solid var(--lp-accent); margin:0 auto 1rem; display:block;"
                         alt="<?php echo htmlspecialchars($tm['name'] ?? 'Team member', ENT_QUOTES); ?>">

                <?php else: ?>

                    <div style="width:100px; height:100px; border-radius:50%; background:var(--lp-surface); border:3px solid var(--lp-accent); margin:0 auto 1rem; display:flex; align-items:center; justify-content:center;">

                        <svg style="width:48px; height:48px; color:var(--lp-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>

                        </svg>

                    </div>

                <?php endif; ?>

                <h4 style="font-size:1rem; font-weight:700; color:#fff; margin-bottom:.25rem;"><?php echo htmlspecialchars($tm['name']); ?></h4>

                <p style="font-size:.875rem; color:var(--lp-accent-l);"><?php echo htmlspecialchars($tm['role']); ?></p>

            </div>

            <?php endforeach; ?>

        </div>

    </div>

</section>

<?php endif; ?>



<!-- ============================================================

     WHY WORK WITH US (always visible)

     ============================================================ -->

<section class="lp-section-light">

    <div class="lp-wrap">

        <div class="why-us-grid">

            <div class="why-us-content text-center-mobile">

                <p style="font-size:0.8rem; font-weight:700; color:var(--lp-accent); text-transform:uppercase; letter-spacing:.1em; margin-bottom:.75rem;">Why <?php echo $shop_name; ?></p>

                <h2 style="font-size:clamp(1.9rem,4vw,2.8rem); font-weight:800; color:#fff; letter-spacing:-0.025em; margin-bottom:1.5rem; line-height:1.15; padding:0 1rem;">Built on <span style="color:var(--lp-accent);">Quality</span>,<br>Driven by <span style="color:var(--lp-accent);">Results</span></h2>

                <p style="font-size:1rem; color:var(--lp-muted); line-height:1.8; margin-bottom:1.75rem; padding:0 1rem;">

                    We're not just a printing shop — we're your creative partner. From concept to completion, we ensure every detail meets your expectations and exceeds industry standards.

                </p>

                <div class="why-us-buttons">

                    <a href="<?php echo $base_path; ?>/public/services.php" class="lp-btn lp-btn-primary">Explore Services</a>

                    <a href="<?php echo $base_path; ?>/public/products.php" class="lp-btn lp-btn-outline">View Products</a>

                </div>

            </div>

            <div style="display:flex; flex-direction:column; gap:1rem;">

                <?php $perks = [

                    ['title'=>'State-of-the-Art Equipment','desc'=>'We invest in the latest printing technology to guarantee crisp, vivid results every time.'],

                    ['title'=>'Quality Assurance & Precision','desc'=>'Every order goes through strict quality checks to ensure accuracy, durability, and a flawless finish before release.'],

                    ['title'=>'Custom Sizes & Formats','desc'=>'No standard size? No problem. We accommodate virtually any dimension or specification.'],

                    ['title'=>'Fast & Reliable Pickup','desc'=>'Rush orders, same-day pickups, and clear notifications so you know exactly when your order is ready.'],

                ]; ?>

                <?php foreach ($perks as $perk): ?>

                <div style="display:flex; gap:1rem; align-items:flex-start; padding:1.25rem; background:var(--lp-surface); border:1px solid rgba(83,197,224,0.15); border-radius:1rem; box-shadow:0 1px 6px rgba(0,0,0,0.2);">

                    <div style="width:36px; height:36px; background:linear-gradient(135deg,#eaf7fb,#cff1f8); border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px;">

                        <svg style="width:18px;height:18px;color:var(--lp-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>

                        </svg>

                    </div>

                    <div>

                        <div style="font-size:.9375rem; font-weight:700; color:#fff; margin-bottom:.2rem;"><?php echo $perk['title']; ?></div>

                        <div style="font-size:.875rem; color:var(--lp-muted); line-height:1.6;"><?php echo $perk['desc']; ?></div>

                    </div>

                </div>

                <?php endforeach; ?>

            </div>

        </div>

    </div>

</section>



<!-- ============================================================

     CTA

     ============================================================ -->

<section class="lp-section-cta">

    <div class="lp-wrap">

        <div class="lp-cta-inner">

            <h2 class="lp-cta-title">Ready to Start Your Next Print Project?</h2>

            <p class="lp-cta-desc">Join thousands of happy clients who trust <?php echo $shop_name; ?> for all their printing needs.</p>

            <div class="lp-cta-btns">

                <?php if (!is_logged_in()): ?>

                    <a href="#" data-auth-modal="register" class="lp-btn lp-btn-primary">Create Free Account</a>

                    <a href="<?php echo $base_path; ?>/public/services.php" class="lp-btn lp-btn-outline">Our Services</a>

                <?php else: ?>

                    <a href="<?php echo $base_path; ?>/public/products.php" class="lp-btn lp-btn-primary">Browse Products</a>

                <?php endif; ?>

            </div>

        </div>

    </div>

</section>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php require_once __DIR__ . '/../includes/auth-modals.php'; ?>

