<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Required Meta Tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Contact Us - Icon Services</title>
    <!-- SEO -->
    <meta name="description" content="We provide Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services in one place.">
    <meta name="keywords" content="singapore car detailing, singapore car rental, singapore car servicing, singapore valet service, singapore limousine service">
    <meta name="author" content="Icon Services">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://iconcarrentalsg.com/contact-us">
    <meta property="og:site_name" content="Icon Services">
    <meta property="og:description" content="We provide Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services in one place.">
    <meta property="og:keywords" content="singapore car detailing, singapore car rental, singapore car servicing, singapore valet service, singapore limousine service">
    <!-- GSC Code -->
    <meta name="google-site-verification" content="CODEHERE">
    <!-- BWT Code -->
    <meta name="msvalidate.01" content="CODEHERE">
    <!-- Canonicalization -->
    <link rel="canonical" href="https://iconcarrentalsg.com/contact-us">
    <!-- Schema -->
    <script type="application/ld+json">
      {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "Icon Services",
        "alternateName": "Icon Services",
        "url": "URL",
        "description": "We provide Car Detailing, Car Rental, Car Servicing, Valet, and Limousine Services in one place.",
        "logo": "https://iconcarrentalsg.com/img/icon.png",
        "sameAs": [
          "https://www.facebook.com/HERE/",
          "https://tiktok.com/HERE/",
          "https://www.instagram.com/HERE/"
        ]
      }
    </script>
    <!-- Global site tag (gtag.js) - Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=IDHERE"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag() {
        dataLayer.push(arguments);
      }
      gtag("js", new Date());
      gtag("config", "IDHERE");
    </script>
    <!-- Favicon -->
    <link rel="icon" href="./img/favicon.ico" type="image/x-icon">
    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./css/styles.css">
    <style>
        /* Hide honeypot field */
        .form-honeypot {
            display: none;
        }
        /* Ensure form elements are properly spaced */
        .form-control.is-invalid {
            border-color: #dc3545;
        }
        .invalid-feedback {
            display: none;
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
        }
        .form-control.is-invalid ~ .invalid-feedback {
            display: block;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-xl navbar-light bg-white fixed-top">
        <div class="container">
            <a class="navbar-brand" href="./">
                <img src="./img/icon.png" alt="Icon Services Logo" class="logo" style="max-height: 140px;">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link active" href="./">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="./car-detailing">Car Detailing</a></li>
                    <li class="nav-item"><a class="nav-link" href="./car-rental">Car Rental</a></li>
                    <li class="nav-item"><a class="nav-link" href="./servicing-and-valet">Servicing & Valet</a></li>
                    <li class="nav-item"><a class="nav-link" href="./limousine-service">Limousine</a></li>
                    <li class="nav-item"><a class="nav-link" href="./contact-us">Contact Us</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Contact Section -->
    <section class="py-5 bg-light" style="padding-top: 8rem !important;">
        <div class="container">
            <h2 class="text-center fw-bold mb-5 animate__animated animate__fadeIn">Contact Us</h2>
            <p class="lead text-center mb-5">We'd love to hear from you! Reach out for inquiries or bookings.</p>
            <div class="row">
                <!-- Contact Form -->
                <div class="col-md-6 mb-4">
                    <div class="p-4 bg-white shadow-sm rounded">
                        <h4 class="fw-bold mb-4">Send Us a Message</h4>
                        <!-- Formspree form -->
                        <form id="contact-form" action="https://formspree.io/f/mldbnezj" method="POST">
                            <!-- Redirect to thank-you page after submission -->
                            <input type="hidden" name="_next" value="./thank-you.php">
                            <!-- Honeypot field for spam protection -->
                            <input type="text" name="_gotcha" class="form-honeypot">
                            <div class="mb-3">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                                <div class="invalid-feedback">Please enter your name.</div>
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                                <div class="invalid-feedback">Please enter a valid email address.</div>
                            </div>
                            <div class="mb-3">
                                <label for="message" class="form-label">Message</label>
                                <textarea class="form-control" id="message" name="message" rows="5" required></textarea>
                                <div class="invalid-feedback">Please enter your message.</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Send Message</button>
                        </form>
                    </div>
                </div>
                <!-- Contact Information -->
                <div class="col-md-6 mb-4">
                    <div class="p-4 bg-white shadow-sm rounded">
                        <h4 class="fw-bold mb-4">Get in Touch</h4>
                        <p><strong>Address:</strong> 123 Automotive Avenue, Singapore 123456</p>
                        <p><strong>Phone:</strong> +65 1234 5678</p>
                        <p><strong>Email:</strong> <a href="mailto:contact@icondetailing.sg">contact@icondetailing.sg</a></p>
                        <p><strong>Operating Hours:</strong> Mon-Sat, 9:00 AM - 6:00 PM</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Customer Reviews Section -->
    <section class="reviews-section">
        <div class="container">
            <h2 class="text-center fw-bold mb-5 animate__animated animate__fadeIn">What Our Customers Say</h2>
            <div class="row">
                <div class="col-md-4">
                    <div class="review-card">
                        <div class="stars">★★★★★</div>
                        <p class="review-text">"Amazing car detailing service! My car looks brand new."</p>
                        <p class="reviewer-name">Sarah L.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="review-card">
                        <div class="stars">★★★★★</div>
                        <p class="review-text">"The limousine service was perfect for our wedding day."</p>
                        <p class="reviewer-name">James R.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="review-card">
                        <div class="stars">★★★★☆</div>
                        <p class="review-text">"Great rental experience, the car was clean and reliable."</p>
                        <p class="reviewer-name">Emily T.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer-section">
        <div class="container">
            <div class="row">
                <!-- Logo and Company Info -->
                <div class="col-md-4 mb-4">
                    <a href="./">
                        <img src="./img/icon.png" alt="Icon Services Logo" class="footer-logo mb-3" style="max-width: 150px; max-height: 150px;">
                    </a>
                    <p class="text-white">Icon Services Pte. Ltd.<br>
                    UEN: XXYYZZ123</p>
                </div>
                <!-- Quick Links -->
                <div class="col-md-4 mb-4">
                    <h5 class="text-white fw-bold mb-3">Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="./" class="footer-link">Home</a></li>
                        <li><a href="./car-detailing" class="footer-link">Car Detailing</a></li>
                        <li><a href="./car-rental" class="footer-link">Car Rental</a></li>
                        <li><a href="./servicing-and-valet" class="footer-link">Servicing & Valet</a></li>
                        <li><a href="./limousine-service" class="footer-link">Limousine</a></li>
                        <li><a href="./contact-us" class="footer-link">Contact Us</a></li>
                    </ul>
                </div>
                <!-- Social Links -->
                <div class="col-md-4 mb-4">
                    <h5 class="text-white fw-bold mb-3">Follow Us</h5>
                    <div class="social-links mb-3">
                        <a href="https://www.instagram.com/yourprofile" target="_blank" class="text-white me-3" title="Instagram">
                            <i class="bi bi-instagram" style="font-size: 1.5rem;"></i>
                        </a>
                        <a href="https://www.facebook.com/yourprofile" target="_blank" class="text-white me-3" title="Facebook">
                            <i class="bi bi-facebook" style="font-size: 1.5rem;"></i>
                        </a>
                        <a href="https://www.tiktok.com/@yourprofile" target="_blank" class="text-white" title="TikTok">
                            <i class="bi bi-tiktok" style="font-size: 1.5rem;"></i>
                        </a>
                    </div>
                    <p class="text-white" style="font-size: x-small;">Developed by <a href="https://nxstudios.sg" class="footer-link">NXStudios</a>a part of NXGroup.</p>
                </div>
            </div>
            <div class="text-center text-white mt-4 pt-3 border-top">
                <p class="mb-0">Copyright © 2025 Icon Services Pte. Ltd. All Rights Reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Chatbox Widget -->
    <div class="chatbox-widget">
        <div class="chatbox-toggle">
            <i class="bi bi-chat-dots-fill"></i>
        </div>
        <div class="chatbox-content">
            <h5 class="chatbox-title">Chat with Us</h5>
            <a href="https://wa.me/6598765432?text=Hello,%20I%20need%20assistance!" target="_blank" class="chatbox-link">
                <img src="https://cdn-icons-png.flaticon.com/128/3670/3670051.png" alt="WhatsApp" class="chatbox-icon">
                WhatsApp
            </a>
            <a href="https://line.me/R/ti/p/@your-line-id" target="_blank" class="chatbox-link">
                <img src="https://cdn-icons-png.flaticon.com/128/3670/3670089.png" alt="LINE" class="chatbox-icon">
                LINE
            </a>
            <a href="weixin://dl/chat?your-wechat-id" target="_blank" class="chatbox-link">
                <img src="https://cdn-icons-png.flaticon.com/128/3670/3670101.png" alt="WeChat" class="chatbox-icon">
                WeChat
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="./js/script.js"></script>
    <script>
        // Client-side form validation
        document.getElementById('contact-form').addEventListener('submit', function(event) {
            const form = this;
            let isValid = true;

            // Reset previous validation states
            form.querySelectorAll('.form-control').forEach(input => {
                input.classList.remove('is-invalid');
            });

            // Validate name
            const name = form.querySelector('#name');
            if (!name.value.trim()) {
                name.classList.add('is-invalid');
                isValid = false;
            }

            // Validate email
            const email = form.querySelector('#email');
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(email.value)) {
                email.classList.add('is-invalid');
                isValid = false;
            }

            // Validate message
            const message = form.querySelector('#message');
            if (!message.value.trim()) {
                message.classList.add('is-invalid');
                isValid = false;
            }

            if (!isValid) {
                event.preventDefault();
            }
        });

        // Chatbox toggle functionality
        document.querySelector('.chatbox-toggle').addEventListener('click', function () {
            const content = document.querySelector('.chatbox-content');
            content.style.display = content.style.display === 'block' ? 'none' : 'block';
        });
    </script>
</body>
</html>