<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Thank You - Icon Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./css/styles.css">
    <link rel="icon" href="./img/favicon.ico" type="image/x-icon">
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
                    <li class="nav-item"><a class="nav-link" href="./">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="./car-detailing">Car Detailing</a></li>
                    <li class="nav-item"><a class="nav-link" href="./car-rental">Car Rental</a></li>
                    <li class="nav-item"><a class="nav-link" href="./servicing-and-valet">Servicing & Valet</a></li>
                    <li class="nav-item"><a class="nav-link" href="./limousine-service">Limousine</a></li>
                    <li class="nav-item"><a class="nav-link active" href="./contact-us">Contact Us</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Thank You Section -->
    <section class="py-5 bg-light" style="padding-top: 8rem !important;">
        <div class="container text-center">
            <h2 class="fw-bold mb-4">Thank You!</h2>
            <p class="lead mb-4">Your message has been sent successfully. We will get back to you soon.</p>
            <a href="./contact-us" class="btn btn-primary">Back to Contact Us</a>
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
        // Chatbox toggle functionality
        document.querySelector('.chatbox-toggle').addEventListener('click', function () {
            const content = document.querySelector('.chatbox-content');
            content.style.display = content.style.display === 'block' ? 'none' : 'block';
        });
    </script>
</body>
</html>