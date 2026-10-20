<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AICS Portal | Sign In</title>
    <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
    <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
    <!-- Google Fonts: Inter & Caveat for Antique signature script -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Inter:wght@300;400;500;600;700;800&display=swap"
      rel="stylesheet"
    />
    <link
      rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    />
    <link rel="stylesheet" href="css files/login.css?v=accepted-ids-v1" />
  </head>
  <body>
    <div class="login-page-container">
      <main class="login-stage auth-stage is-login-view">

        <!-- ==============================================
             HERO PANEL: Desktop Left Panel / Mobile Top Wave
             ============================================== -->
        <section class="hero-panel" aria-label="AICS Antique Portal Branding">
          <!-- Back Link -->
          <a href="../../landingpage.php" class="hero-back-btn" aria-label="Back to Homepage">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            <span>Back</span>
          </a>

          <!-- Scenic Antique Background with Aerial Coast & Mountain -->
          <div class="hero-scenic-layer" aria-hidden="true">
            <div class="hero-scenic-img"></div>
            <div class="hero-scenic-gradient"></div>
            <!-- Historic Antique Belfry / Church Tower Accent -->
            <div class="hero-landmark-tower"></div>
          </div>

          <!-- Layered Bottom Waves (SVG) -->
          <div class="hero-wave-wrapper" aria-hidden="true">
            <svg class="hero-wave-svg" viewBox="0 0 1440 320" preserveAspectRatio="none">
              <path class="wave-back" fill="rgba(56, 189, 248, 0.35)" d="M0,192L48,197.3C96,203,192,213,288,192C384,171,480,117,576,128C672,139,768,213,864,229.3C960,245,1056,203,1152,176C1248,149,1344,139,1392,133.3L1440,128L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
              <path class="wave-mid" fill="rgba(2, 132, 199, 0.65)" d="M0,256L48,245.3C96,235,192,213,288,218.7C384,224,480,256,576,261.3C672,267,768,245,864,218.7C960,192,1056,160,1152,165.3C1248,171,1344,213,1392,234.7L1440,256L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
              <path class="wave-front" fill="#0084d1" d="M0,288L48,272C96,256,192,224,288,229.3C384,235,480,277,576,282.7C672,288,768,256,864,240C960,224,1056,224,1152,240C1248,256,1344,288,1392,304L1440,320L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path>
            </svg>
          </div>

          <!-- Hero Central Brand Identity -->
          <div class="hero-brand-content">
            <div class="hero-icon-mark">
              <img
                src="../asset/logoinphone.png"
                alt="AICS Portal"
                class="brand-icon-img"
                onerror="this.src='../../asset/logoinphone.png'"
              />
            </div>
            <h1 class="hero-title">Aics Portal</h1>
            <p class="hero-subtitle">PSWDO - Antique</p>
            <div class="hero-accent-divider" aria-hidden="true"></div>
          </div>

          <!-- Cursive 'Antique' Signature Accent -->
          <div class="hero-signature" aria-hidden="true">
            <span>Antique</span>
          </div>
        </section>

        <!-- ==============================================
             AUTH PANEL: Floating White Card
             ============================================== -->
        <section class="auth-panel">
          <div class="auth-card">

            <!-- Card Brand Logo -->
            <div class="card-logo-header">
              <a href="../../landingpage.php" class="card-logo-link" aria-label="AICS Portal Home">
                <img
                  src="../asset/Logo.png"
                  alt="Aics Portal PSWDO - Antique"
                  class="card-brand-logo"
                  onerror="this.src='../../asset/logo.png'"
                />
              </a>
            </div>

            <!-- Status Alert Banner (Disabled in favor of Floating Toaster) -->
            <div id="auth-status" class="auth-status hidden" style="display:none !important;" role="status" aria-hidden="true"></div>

            <!-- ==============================
                 LOGIN FORM
                 ============================== -->
            <div id="login-form" class="form-section">
              <div class="form-heading">
                <h2 class="form-title">Welcome Back</h2>
                <p class="form-desc">Please enter your details to sign in.</p>
              </div>

              <form
                id="login-access-form"
                action="php_files/auth-login.php"
                method="post"
                novalidate
              >
                <!-- Email or Mobile Number Input -->
                <div class="input-group">
                  <label for="login-identifier">Email or Mobile Number</label>
                  <div class="input-wrapper">
                    <i class="fas fa-user input-icon" aria-hidden="true"></i>
                    <input
                      id="login-identifier"
                      type="text"
                      name="login_identifier"
                      placeholder="Email or 09XXXXXXXXX"
                      required
                      autocomplete="username"
                    />
                  </div>
                </div>

                <!-- Password Input with Toggle -->
                <div class="input-group">
                  <label for="login-password">Password</label>
                  <div class="input-wrapper">
                    <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                    <input
                      id="login-password"
                      type="password"
                      name="password"
                      placeholder="••••••••"
                      required
                      autocomplete="current-password"
                    />
                    <button
                      type="button"
                      class="password-toggle"
                      data-password-toggle
                      data-target="login-password"
                      aria-label="Show password"
                      aria-pressed="false"
                    >
                      <i class="fas fa-eye" aria-hidden="true"></i>
                    </button>
                  </div>
                </div>

                <!-- Options: Remember me & Forgot Password -->
                <div class="options-row">
                  <label class="remember-checkbox-label">
                    <input type="checkbox" name="remember_me" id="remember-me" checked />
                    <span class="custom-checkbox" aria-hidden="true"></span>
                    <span class="remember-text">Remember me</span>
                  </label>
                  <button type="button" class="forgot-password-link forgot-password-trigger" id="forgot-password-trigger">Forgot Password?</button>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-primary btn-signin">
                  <span>SIGN IN</span>
                  <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </button>
              </form>

              <!-- Footer Links -->
              <div class="auth-footer-block">
                <p class="signup-prompt">
                  Don't have an account?
                  <span class="link-action" onclick="toggleAuth('register')" role="button" tabindex="0">Sign Up</span>
                </p>
                <p class="offline-prompt">
                  Can't submit online?
                  <a href="blank-gis.php" target="_blank" class="gis-download-link">Download Blank GIS Form</a>
                </p>
              </div>
            </div>

            <!-- ==============================
                 REGISTER FORM (2-Step Flow)
                 ============================== -->
            <div id="register-form" class="form-section hidden">

              <!-- Signup Top Header Bar with Back & Official Logo -->
              <div class="signup-top-bar">
                <button type="button" class="signup-back-btn" id="signup-back-btn" aria-label="Go back">
                  <i class="fas fa-arrow-left" aria-hidden="true"></i>
                  <span>Back</span>
                </button>
                <div class="signup-logo-wrap">
                  <a href="../../landingpage.php" aria-label="AICS Portal Home">
                    <img
                      src="../asset/Logo.png"
                      alt="Aics Portal PSWDO - Antique"
                      class="signup-brand-logo"
                      onerror="this.src='../../asset/logo.png'"
                    />
                  </a>
                </div>
              </div>
              <div class="signup-top-divider" aria-hidden="true"></div>

              <!-- 2-Step Progress Indicator -->
              <div class="signup-stepper" aria-label="Registration progress">
                <div class="step-node is-active" id="step-node-1" data-step="1" role="button" tabindex="0" aria-label="Step 1: Your details">
                  <span class="step-num">1</span>
                  <span class="step-label">Your details</span>
                </div>
                <div class="step-track" aria-hidden="true">
                  <div class="step-track-fill" id="step-track-fill"></div>
                </div>
                <div class="step-node" id="step-node-id" data-step="id" role="button" tabindex="0" aria-label="Step 2: Valid ID">
                  <span class="step-num">2</span>
                  <span class="step-label">Valid ID</span>
                </div>
                <div class="step-track" aria-hidden="true">
                  <div class="step-track-fill" id="step-track-fill-id"></div>
                </div>
                <div class="step-node" id="step-node-2" data-step="2" role="button" tabindex="0" aria-label="Step 3: Security">
                  <span class="step-num">3</span>
                  <span class="step-label">Security</span>
                </div>
              </div>

              <!-- Heading -->
              <div class="form-heading signup-heading">
                <h2 class="form-title">Create an Account</h2>
                <p class="form-desc">Join us to apply and track your AICS requests.</p>
              </div>

              <form
                id="register-access-form"
                action="php_files/auth-signup.php"
                method="post"
                novalidate
              >
                <input type="hidden" id="signup-attempt-id" name="signup_attempt_id" value="" />
                <!-- ==================== STEP 1: PERSONAL INFO ==================== -->
                <div id="signup-step-1" class="signup-step-content">
                  <div class="signup-fields-step1">
                    <!-- First Name -->
                    <div class="input-group signup-field-half">
                      <label for="register-first-name">First Name</label>
                      <div class="input-wrapper">
                        <i class="fas fa-user input-icon" aria-hidden="true"></i>
                        <input
                          id="register-first-name"
                          type="text"
                          name="first_name"
                          placeholder="Maria"
                          required
                          autocomplete="given-name"
                        />
                      </div>
                    </div>

                    <!-- Last Name -->
                    <div class="input-group signup-field-half">
                      <label for="register-last-name">Last Name</label>
                      <div class="input-wrapper">
                        <i class="fas fa-user input-icon" aria-hidden="true"></i>
                        <input
                          id="register-last-name"
                          type="text"
                          name="last_name"
                          placeholder="Reyes"
                          required
                          autocomplete="family-name"
                        />
                      </div>
                    </div>

                    <!-- Middle name or initial is optional; if entered, it must match the ID. -->
                    <div class="input-group signup-field-half">
                      <label for="register-middle-name">Middle Name <span class="field-optional">(if any)</span></label>
                      <div class="input-wrapper">
                        <i class="fas fa-user input-icon" aria-hidden="true"></i>
                        <input
                          id="register-middle-name"
                          type="text"
                          name="middle_name"
                          placeholder="e.g. Santos or S."
                          maxlength="80"
                          autocomplete="additional-name"
                          title="Enter your middle name or initial if you have one, or leave this blank."
                        />
                      </div>
                    </div>

                    <!-- Email Address (Optional) -->
                    <div class="input-group signup-field-half">
                      <label for="register-email">Email Address <span class="field-optional">(Optional)</span></label>
                      <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon" aria-hidden="true"></i>
                        <input
                          id="register-email"
                          type="email"
                          name="email"
                          placeholder="maria@example.com (optional)"
                          autocomplete="email"
                        />
                      </div>
                    </div>

                    <!-- Date of Birth -->
                    <div class="input-group signup-field-half">
                      <label for="register-birthdate">Date of Birth <span class="field-required">*</span></label>
                      <div class="input-wrapper" id="register-birthdate-wrapper">
                        <i class="fas fa-calendar-alt input-icon" aria-hidden="true"></i>
                        <input
                          id="register-birthdate"
                          type="date"
                          lang="en-US"
                          required
                          autocomplete="bday"
                        />
                        <input id="register-birthdate-iso" type="hidden" name="date_of_birth" />
                      </div>
                      <small id="register-birthdate-selected" class="signup-date-preview hidden" aria-live="polite"></small>
                      <small id="register-birthdate-error" class="signup-validation-message hidden" role="alert"></small>
                    </div>

                    <!-- Age (Auto-calculated) -->
                    <div class="input-group signup-field-half">
                      <label for="register-age">Age <span class="field-optional">(Auto-calculated)</span></label>
                      <div class="input-wrapper is-readonly" id="register-age-wrapper">
                        <i class="fas fa-hourglass-half input-icon" aria-hidden="true"></i>
                        <input
                          id="register-age"
                          type="text"
                          name="age"
                          placeholder="Auto-calculated"
                          readonly
                          tabindex="-1"
                          aria-readonly="true"
                        />
                      </div>
                      <small id="register-age-error" class="signup-validation-message hidden" role="alert"></small>
                    </div>

                    <!-- Phone Number -->
                    <div class="input-group signup-field-full">
                      <label for="register-phone">Phone Number</label>
                      <div class="input-wrapper">
                        <i class="fas fa-phone input-icon" aria-hidden="true"></i>
                        <input
                          id="register-phone"
                          type="tel"
                          name="contact_number"
                          placeholder="09XXXXXXXXX"
                          pattern="(?:09[0-9]{9}|639[0-9]{9})"
                          inputmode="numeric"
                          maxlength="12"
                          data-digits-only
                          required
                          autocomplete="tel"
                        />
                      </div>
                      <small id="register-phone-error" class="signup-validation-message hidden" role="alert">Please enter a valid Philippine mobile number (e.g. 09XXXXXXXXX).</small>
                    </div>
                  </div>

                  <div class="signup-terms">
                    <input id="register-terms" type="checkbox" name="accept_terms" value="1" required />
                    <span><label for="register-terms">I agree to the </label><a href="#signup-terms-modal" id="signup-terms-link" aria-haspopup="dialog">Terms and Conditions</a>.</span>
                  </div>

                  <!-- Step 1 Next Button -->
                  <button
                    type="button"
                    class="btn btn-primary btn-step-action btn-step-next"
                    id="btn-goto-step-2"
                    disabled
                  >
                    <span>NEXT</span>
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                  </button>
                </div>

                <!-- ==================== STEP 2: VALID ID ==================== -->
                <div id="signup-step-id" class="signup-step-content signup-id-step hidden">
                  <div class="signup-id-head">
                    <span class="signup-id-head-icon" aria-hidden="true"><i class="fas fa-id-card"></i></span>
                    <div>
                      <h3>Verify your valid ID</h3>
                      <p>Add clear photos of the <strong>front</strong> and <strong>back</strong> of one valid ID. For a one-sided voter's certification, NBI clearance, or police clearance, add a second photo of the same document. The name on your ID must match the name you entered.</p>
                    </div>
                  </div>
                  <!-- Keep in sync with SUPPORTED_IDS in ai_processor/id_check.py -->
                  <div class="signup-id-accepted">
                    <p class="signup-id-accepted-title"><i class="fas fa-circle-info" aria-hidden="true"></i> Accepted government IDs</p>
                    <ul class="signup-id-accepted-list">
                      <li>PhilSys National ID</li>
                      <li>Driver's License</li>
                      <li>Passport</li>
                      <li>UMID</li>
                      <li>SSS ID</li>
                      <li>Voter's ID</li>
                      <li>Postal ID</li>
                      <li>PRC ID</li>
                      <li>PhilHealth ID</li>
                      <li>Senior Citizen ID</li>
                      <li>PWD ID</li>
                    </ul>
                    <details class="signup-id-accepted-more">
                      <summary>See all accepted IDs</summary>
                      <ul class="signup-id-accepted-list">
                        <li>GSIS ID</li>
                        <li>Pag-IBIG ID</li>
                        <li>OWWA ID</li>
                        <li>DOLE ID</li>
                        <li>4Ps ID</li>
                        <li>Solo Parent ID</li>
                        <li>Barangay ID</li>
                        <li>City or Municipal Government ID</li>
                        <li>Voter's Certification</li>
                        <li>NBI Clearance</li>
                        <li>Police Clearance</li>
                        <li>Other government ID showing the issuing agency</li>
                      </ul>
                    </details>
                    <p class="signup-id-not-accepted"><i class="fas fa-ban" aria-hidden="true"></i> Not accepted: school or student IDs, company or employee IDs, membership cards, and barangay certificates.</p>
                  </div>
                  <div class="signup-id-tiles">
                    <label class="signup-id-tile" for="id-front-input" id="id-front-tile">
                      <input type="file" id="id-front-input" name="id_front" accept="image/jpeg,image/png,image/webp" capture="environment" class="signup-id-input" />
                      <span class="signup-id-placeholder">
                        <span class="signup-id-tile-icon"><i class="fas fa-camera" aria-hidden="true"></i></span>
                        <strong>Front of ID</strong>
                        <small>Tap to take or choose a photo</small>
                      </span>
                      <img class="signup-id-thumb hidden" id="id-front-thumb" alt="Front of your ID" />
                      <span class="signup-id-tile-badge"><i class="fas fa-check" aria-hidden="true"></i> Front</span>
                    </label>
                    <label class="signup-id-tile" for="id-back-input" id="id-back-tile">
                      <input type="file" id="id-back-input" name="id_back" accept="image/jpeg,image/png,image/webp" capture="environment" class="signup-id-input" />
                      <span class="signup-id-placeholder">
                        <span class="signup-id-tile-icon"><i class="fas fa-camera-rotate" aria-hidden="true"></i></span>
                        <strong>Back or second view</strong>
                        <small>Tap to take or choose a photo</small>
                      </span>
                      <img class="signup-id-thumb hidden" id="id-back-thumb" alt="Back of your ID" />
                      <span class="signup-id-tile-badge"><i class="fas fa-check" aria-hidden="true"></i> Back</span>
                    </label>
                  </div>
                  <ul class="signup-id-tips">
                    <li><i class="fas fa-circle-check" aria-hidden="true"></i> Whole card visible</li>
                    <li><i class="fas fa-circle-check" aria-hidden="true"></i> Good light, no glare</li>
                    <li><i class="fas fa-circle-check" aria-hidden="true"></i> Original card, not a screenshot</li>
                  </ul>
                  <div class="signup-id-result hidden" id="signup-id-result" role="status" aria-live="polite"></div>
                  <div class="signup-id-actions">
                    <button type="button" class="btn btn-primary btn-step-action" id="btn-scan-id" disabled>
                      <i class="fas fa-shield-halved" aria-hidden="true"></i>
                      <span>SCAN MY ID</span>
                    </button>
                    <button type="button" class="btn btn-primary btn-step-action hidden" id="btn-id-continue">
                      <span>CONTINUE</span>
                      <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </button>
                  </div>
                </div>

                <!-- ==================== STEP 3: SECURITY & CREDENTIALS ==================== -->
                <div id="signup-step-2" class="signup-step-content hidden">
                  <div class="signup-fields-step2">
                    <!-- OTP Code -->
                    <div class="input-group signup-field-full">
                      <label for="register-otp">OTP Code</label>
                      <div class="signup-otp-controls">
                        <div class="input-wrapper">
                          <i class="fas fa-shield-halved input-icon" aria-hidden="true"></i>
                          <input
                            id="register-otp"
                            type="text"
                            name="otp_code"
                            placeholder="OTP Code"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            data-digits-only
                            autocomplete="one-time-code"
                            aria-describedby="register-otp-note"
                          />
                        </div>
                        <button type="button" class="btn signup-otp-button" id="btn-send-otp">
                          <i class="fas fa-paper-plane" id="otp-btn-icon" aria-hidden="true" style="margin-right: 6px;"></i>
                          <span id="otp-btn-text">SEND OTP</span>
                        </button>
                      </div>
                      <small id="register-otp-note" class="signup-field-note">Click "SEND OTP" to receive a verification code on your mobile number.</small>
                    </div>

                    <!-- Password -->
                    <div class="input-group signup-field-full">
                      <label for="register-password">Password</label>
                      <div class="input-wrapper">
                        <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                        <input
                          id="register-password"
                          type="password"
                          name="password"
                          placeholder="At least 8 characters"
                          minlength="8"
                          pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])\S{8,}"
                          title="Use at least 8 characters with at least one uppercase letter, one lowercase letter, and one number. Spaces are not allowed."
                          required
                          autocomplete="new-password"
                        />
                        <button
                          type="button"
                          class="password-toggle"
                          data-password-toggle
                          data-target="register-password"
                          aria-label="Show password"
                          aria-pressed="false"
                        >
                          <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                      </div>
                      <small id="register-password-error" class="signup-validation-message hidden" role="alert">Password does not meet requirements (min 8 chars, 1 uppercase, 1 lowercase, 1 number).</small>
                    </div>

                    <!-- Confirm Password -->
                    <div class="input-group signup-field-full">
                      <label for="register-confirm-password">Confirm Password</label>
                      <div class="input-wrapper">
                        <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                        <input
                          id="register-confirm-password"
                          type="password"
                          name="confirm_password"
                          placeholder="Re-enter password"
                          minlength="8"
                          required
                          autocomplete="new-password"
                        />
                        <button
                          type="button"
                          class="password-toggle"
                          data-password-toggle
                          data-target="register-confirm-password"
                          aria-label="Show confirm password"
                          aria-pressed="false"
                        >
                          <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                      </div>
                      <small id="register-confirm-password-error" class="signup-validation-message hidden" role="alert">Passwords do not match.</small>
                    </div>
                  </div>

                  <!-- Step 2 Submit Button -->
                  <button
                    type="submit"
                    class="btn btn-primary btn-step-action btn-signup-submit"
                    id="btn-submit-signup"
                  >
                    <span>CREATE ACCOUNT</span>
                    <i class="fas fa-user-plus" aria-hidden="true"></i>
                  </button>
                </div>
              </form>

              <!-- Already have an account prompt -->
              <div class="signup-login-prompt">
                <span class="prompt-rule" aria-hidden="true"></span>
                <span class="prompt-text">
                  Already have an account?
                  <span class="link-action" onclick="toggleAuth('login')" role="button" tabindex="0">Log In</span>
                </span>
                <span class="prompt-rule" aria-hidden="true"></span>
              </div>

              <!-- Footer Landscape Wave Illustration -->
              <div class="signup-card-footer" aria-hidden="true">
                <img
                  src="../asset/signup-footer-wave.png"
                  alt=""
                  class="signup-footer-img"
                  onerror="this.src='../../asset/signup-footer-wave.png'"
                />
              </div>

            </div>

          </div>
        </section>

      </main>
    </div>

    <!-- Applicant terms and conditions -->
    <div id="signup-terms-modal" class="auth-popup-modal hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="signup-terms-title" aria-describedby="signup-terms-intro">
      <div class="auth-popup-backdrop" data-terms-close></div>
      <section class="auth-popup-card signup-terms-card" tabindex="-1">
        <button type="button" class="signup-terms-close" data-terms-close aria-label="Close terms and conditions">&times;</button>
        <h2 id="signup-terms-title">Terms and Conditions</h2>
        <p id="signup-terms-intro"><strong>AICS Applicant Portal — Provincial Social Welfare and Development Office (PSWDO), Antique.</strong> By creating an account or using the AICS Applicant Portal, you acknowledge and agree to comply with the following Terms and Conditions.</p>
        <div class="signup-terms-content">
          <section>
            <h3>1. Eligibility and Account Registration</h3>
            <p>Applicants must be at least 18 years old to register. Each applicant must provide accurate personal information, a valid identification document, and active contact details. Accounts are intended for individual use and must not be shared with unauthorized persons.</p>
          </section>
          <section>
            <h3>2. Accurate Information and Documents</h3>
            <p>Applicants must submit complete, accurate, and truthful information and supporting documents. Submission of falsified, altered, misleading, or unauthorized documents is prohibited. PSWDO may request additional information or corrections during application review.</p>
          </section>
          <section>
            <h3>3. Collection and Use of Personal Information</h3>
            <p>The portal collects and processes personal information and supporting documents for identity verification, application pre-screening, document verification, duplicate detection, application monitoring, prioritization assistance, and notification purposes.</p>
            <p>Personal information shall be handled in accordance with Republic Act No. 10173, or the Data Privacy Act of 2012, and other applicable regulations. Further details, including the legal basis for processing, are provided in the portal's Privacy Notice.</p>
          </section>
          <section>
            <h3>4. Automated Document Verification</h3>
            <p>The portal may use automated technologies, including artificial intelligence, to assist in verifying uploaded documents, identifying possible inconsistencies, and supporting application review. Automated results are supplementary and remain subject to evaluation by authorized PSWDO personnel. Automated verification does not guarantee application approval.</p>
          </section>
          <section>
            <h3>5. Application Assessment and Approval</h3>
            <p>Submitting an application does not guarantee eligibility, approval, financial assistance, or a specific release date. All applications remain subject to PSWDO evaluation, applicable AICS guidelines, required documentation, and availability of resources.</p>
          </section>
          <section>
            <h3>6. Notifications and Communication</h3>
            <p>The portal may send application updates, document correction requests, screening instructions, and assistance release notifications through SMS or the applicant dashboard. Applicants are responsible for maintaining accurate contact information and regularly checking their application status.</p>
          </section>
          <section>
            <h3>7. Information Sharing and Confidentiality</h3>
            <p>Applicant information may be accessed or shared with authorized personnel, relevant government offices, and service providers only when necessary and legally permitted for AICS processing and distribution. Applicable privacy and security safeguards shall be observed, as further described in the Privacy Notice.</p>
          </section>
          <section>
            <h3>8. Data Protection and Retention</h3>
            <p>PSWDO shall implement appropriate measures to protect applicant information against unauthorized access, misuse, alteration, or disclosure. Personal information shall be retained and securely disposed of in accordance with applicable laws, government records requirements, and the retention periods disclosed in the Privacy Notice.</p>
          </section>
          <section>
            <h3>9. Applicant Privacy Rights</h3>
            <p>Applicants may exercise their applicable rights under the Data Privacy Act of 2012, including requesting access to or correction of their personal information, objecting to certain processing activities, and filing privacy-related complaints. Requests may be submitted through PSWDO's official service or privacy contact channels.</p>
          </section>
          <section>
            <h3>10. Account Security and Responsible Use</h3>
            <p>Applicants must keep their passwords and verification codes confidential. Unauthorized access, impersonation, fraudulent submissions, and activities intended to disrupt portal operations are prohibited. PSWDO may restrict access when necessary to address misuse, subject to applicable procedures.</p>
          </section>
          <section>
            <h3>11. Changes to the Terms and Conditions</h3>
            <p>These Terms and Conditions may be updated to reflect changes in portal functionality, applicable laws, or PSWDO procedures. The current version shall be made available through the portal, and material changes shall be communicated as appropriate.</p>
          </section>
          <section>
            <h3>12. Questions and Concerns</h3>
            <p>For application inquiries, document corrections, account concerns, or privacy-related requests, applicants may contact the Provincial Social Welfare and Development Office of Antique through its officially designated service channels.</p>
          </section>
        </div>
        <p class="signup-terms-acknowledgement">By selecting "I Agree," I acknowledge that I have read and understood these Terms and Conditions and agree to comply with them.</p>
        <div class="signup-terms-actions">
          <label class="signup-terms-consent" for="register-terms-modal">
            <input id="register-terms-modal" type="checkbox" value="1" />
            <span>I Agree</span>
          </label>
          <button type="button" class="btn btn-primary" id="signup-terms-close" data-terms-close>Close</button>
        </div>
      </section>
    </div>

    <!-- Applicant password reset -->
    <div id="password-reset-modal" class="auth-popup-modal hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="password-reset-title">
      <div class="auth-popup-backdrop" data-reset-close></div>
      <section class="auth-popup-card password-reset-card">
        <button type="button" class="password-reset-close" data-reset-close aria-label="Close password reset">&times;</button>
        <header class="auth-popup-head">
          <div class="auth-popup-icon password-reset-icon"><i class="fas fa-key" aria-hidden="true"></i></div>
          <div>
            <h3 id="password-reset-title">Reset your password</h3>
            <p>Enter the mobile number on your applicant account. We'll text you a six digit code.</p>
          </div>
        </header>
        <form id="password-reset-form" novalidate>
          <div class="input-group">
            <label for="password-reset-phone">Mobile number</label>
            <div class="input-wrapper">
              <i class="fas fa-phone input-icon" aria-hidden="true"></i>
              <input id="password-reset-phone" type="tel" inputmode="numeric" pattern="(?:09[0-9]{9}|639[0-9]{9})" maxlength="12" data-digits-only autocomplete="tel" placeholder="09XXXXXXXXX" required />
            </div>
          </div>
          <button type="button" id="password-reset-send" class="btn btn-primary password-reset-button">Send SMS code</button>
          <div id="password-reset-fields" class="hidden">
            <p class="password-reset-help">The code expires in 5 minutes. Use the latest code sent to your phone.</p>
            <div class="input-group">
              <label for="password-reset-code">SMS code</label>
              <div class="input-wrapper">
                <i class="fas fa-shield-halved input-icon" aria-hidden="true"></i>
                <input id="password-reset-code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" data-digits-only autocomplete="one-time-code" placeholder="6-digit code" required />
              </div>
            </div>
            <div class="input-group">
              <label for="password-reset-password">New password</label>
              <div class="input-wrapper">
                <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                <input id="password-reset-password" type="password" autocomplete="new-password" required />
                <button type="button" class="password-toggle" data-password-toggle data-target="password-reset-password" aria-label="Show password" aria-pressed="false">
                  <i class="fas fa-eye" aria-hidden="true"></i>
                </button>
              </div>
            </div>
            <div class="input-group">
              <label for="password-reset-confirm">Confirm new password</label>
              <div class="input-wrapper">
                <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                <input id="password-reset-confirm" type="password" autocomplete="new-password" required />
                <button type="button" class="password-toggle" data-password-toggle data-target="password-reset-confirm" aria-label="Show confirm password" aria-pressed="false">
                  <i class="fas fa-eye" aria-hidden="true"></i>
                </button>
              </div>
            </div>
            <p class="password-reset-help">Use at least 8 characters with uppercase, lowercase, and a number. No spaces.</p>
            <button type="submit" id="password-reset-submit" class="btn btn-primary password-reset-button">Reset password</button>
          </div>
          <p id="password-reset-feedback" class="password-reset-feedback" role="status" aria-live="polite"></p>
        </form>
      </section>
    </div>

    <!-- Notice / Feedback Popup Modal -->
    <div id="auth-popup-modal" class="auth-popup-modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="auth-popup-title">
      <div class="auth-popup-backdrop" data-popup-close></div>
      <article class="auth-popup-card">
        <header class="auth-popup-head">
          <div class="auth-popup-icon"><i class="fas fa-shield-halved" aria-hidden="true"></i></div>
          <div>
            <h3 id="auth-popup-title">Account Access Notice</h3>
            <p id="auth-popup-message">Your account status prevents access at this time.</p>
          </div>
        </header>
        <div class="auth-popup-actions">
          <button type="button" class="btn btn-primary" id="auth-popup-ok">OK</button>
        </div>
      </article>
    </div>

    <!-- Floating Toast Notification Center -->
    <div id="toast-container" class="toast-container" aria-live="assertive" aria-atomic="true"></div>

    <script src="../asset/digits-only.js"></script>
    <script src="js files/login.js?v=signup-terms-v2"></script>
    <script src="js files/password-reset.js?v=1"></script>
  </body>
</html>
