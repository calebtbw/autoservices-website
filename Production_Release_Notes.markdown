# Website Release Notes

## Version 1.0.0
*Release Date: [Insert Date]* 

- Database Server Type: MariaDB 10.4.32
- Web Server: Apache 2.4.58
- PHP: >8.2.12

### Step 1: Understand the Release
This is the official production release of Icon Detailing Services. These notes guide you through key features, setup, and support for a smooth experience.
   #### Client-Facing Pages
   - `index.php` - Home / Landing Page
   - `car-detailing.php` - Car Detailing service showcase and booking form
   - `car-rental.php` - Car Rental service showcase and booking form
   - `valet-service.php` - Valet service showcase and booking form
   - `limousine-service.php` - Limousine service showcase and booking form
   - `contact-us.php` - Contact form and company information showcase
   - `thank-you.php` - Thank you form (not shown)

   #### Admin-Facing Pages
   - `admin_login.php` - Admin Login page
   - `admin_logout.php` - Admin Logout page, nothing displayed here, just for ending the session
   - `admin_dashboard.php` - Main Dashboard for Viewing / Managing Bookings, Service Cutoff Controls, Existing Clients, Accounting, and Audit Logs for admin actions
   - `manage_admins.php` - Create or delete admin accounts
   - `manage_limousines.php` - Create, edit or delete existing limousines
   - `manage_vehicles.php` - Create, edit or delete existing vehicles from car rental fleet
   - `manage_slots.php` - Slot manager for bulk creation of car detailing and valet service slots

   #### Functional / Helpers / Utilities Files
   - `check_overlap.php` - Prevents overlapping bookings for car rental service
   - `config.php` (IMPORTANT) - Configuration file containg all the necessary constants for Hitpay, Telegram and Google Maps 
   - `db.php` - Establishing database connection
   - `get_unavailable_slots_limousine.php` - Fetches unavailable booking slots per limousine type for the client facing page
   - `get_unavailable_slots.php` - Fetches unavailable booking slots per vehicle type for the client facing page
   - `hitpay_webhook.php` - Webhook manager for Hitpay, this will help automate certain features when payments and refunds go through
   - `setup_database.php` - Initial Database Setup file, must be deleted after running it post setup
   - `utils.php` - Functions for audit logging, resource release and telegram notifications


### Step 2: Get Started
1. **Generate Hitpay API Keys and SALT Keys**
   - Change in config.php accordingly

2. **Generate Google Maps API Key, Telegram Bot Token, Telegram Group ID**
   - Change in config.php accordingly

3. **Generate Google Site Verification code, Google Analytics code, Bing Webmaster Tools code**
   - Edit the element after `<!-- GSC Code -->` to contain your GSC code
   - Edit the element after `<!-- BWT Code -->` to contain your BWT code
   - Edit the element after `<!-- Global site tag (gtag.js) - Google Analytics -->` to contain your GA code

4. **Edit SEO elements to your requirements**
   - Everything after the `<!-- SEO -->` tag in all client facing pages has to be edited specifically for best results

4. **Change $baseUrl for Production**
   - Pages that contain it might have code looking like this on `car-rental.php` around line 73 `$baseUrl = "$protocol://$host/icon-staging";`
   - Remove `/icon-staging` and it should only be showing `$protocol://$host`
   - Failure to change this will cause errors

5. **Double check file path references** 
   - Some pages such as `car-rental.php` around line 892 `fetch(./iconm3/get_unavailable_slots.php?vehicle_id=${selectedServiceId}&date=${date}&service_type=car_rental)`
   - All similar lines on pages that require it should have already been changed by me, this example above is currently correct and will work in a production setting

6. **Chatbox** 
   - Every client facing page has socials after the footer linking to Whatsapp, Line and WeChat as requested looking something like that
   ```
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
    ```
   - Make sure to edit the links to include your own company number or IDs after the `href` 

7. **Rename htaccess file**
   - The htaccess file ensures admin pages are secure and client facing pages properly redirected
   - In this folder you will find the `htaccess_production` file which is the one required for production, there is also another one called `.htaccess` use for staging reasons
   - You will want to delete the existing `.htaccess` file and rename `htaccess_production` to `.htaccess`.


### Step 3: Choosing a Hosting Provider and Domain Registrar
- Companies like [Vodien](https://www.vodien.com/) can provide you domains and hosting together
- DYOR and choose hosting providers accordingly to your needs and requirements
- I recommend a local provider like Vodien for best loading speeds unless you start serving a large number of international clients in the future

### Step 4: Uploading Website Files and Initial Setup
1. **cPanel Access**
   - Your Hosting provider will grant you access to cPanel, this is where you will manage your website in its entirety
   - Here you can manage your databases and website files

2. **Database Creation**
   - Simply create a database using cPanel's built in MySQL database manager or similar
   - Take note of your database name, the name can be anything choosed by you
   - Take note of your database username and password
   - Edit `db.php` where `$dbname = 'icon_staging';` should be changed to `$dbname = 'YOUR_DATABASE_NAME';`
   - In the same file, edit `$username = 'root';` and `$password = '';` to `$username = 'YOUR_DB_USERNAME';` and `$password = 'YOUR_DB_PASSWORD';`

4. **Upload Website Files**
   - cPanel will have a way for you to upload your website files, but it can only be done one by one
   - The way to bypass this is to have this entire project folder Zipped, and then upload it inside the cPanel htdocs folder
   - Everything in the htdocs folder can be seen by the public once it is uploaded but for now don't worry about that

5. **Setting up the Database**
   - There is no need to setup any tables or columns yourself, I have provided a file that handles this
   - All you need to do once you uploaded all your folders is the type in the URL bar, `YOUR_DOMAIN/iconm3/setup_database.php`
   - This will trigger database creation and populate all the tables and columns as required in your database
   - Once you see `Database setup completed successfully.` in the browser near the top left of the page, this means it has indeed succeeded
   - Verify this by checking that there are tables being created within the table that you originally created

6. **First Admin Login**
   - There is already logic to handle a strong admin password and hashing algorithms to ensure security
   - The initial admin username is `admin` and the password is `BET2z7CT&A%k6v`
   - Once the first admin has logged in, please delete this default admin from the `Admins` tab and create your own admin accounts
   - This will automatically delete the default admin, and populate admin accounts as required by you
   - Note that a strong password will be required, `Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.`

7. **Testing the Functionality**
   - To ensure full functionality, begin generating slots, adding cars and limousines and verify that it shows in the client facing pages properly
   - Create a test booking of any or every service as you wish and verify the functionality
   - There should be no overlap of bookings, slots or anything of the sort

8. **Testing the Payment System**
   - Hitpay will probably require you to set things up before you can start accepting live payments
   - I assume these have already been setup above when you generate your Hitpay API keys
   - Try paying for a test booking in small amounts maybe 10 cents or so as you wish

9. **Verify Full Flow**
   - Booking Made
   - Payment Accepted by Hitpay and Hitpay sends a response to the Webhook Manager
   - Webhook Manager changes payment status to `completed` and booking status to `confirmed` before sending a Telegram notification to the staff group chat
   - If refund payment is pressed, payment status will be set to `refunded` and booking status to `cancelled` before sending a Telegram notification to the staff group chat
   - For services such as car detailing and valet service, an admin has to manually update the status column in the admin dashboard to `completed` when done, this sends a Telegram notification as well notifying group chat that a service has been completed
   - For all services, when a payment is refunded, or if an admin manually changes the booking status to cancelled, the slots for that service will be opened up for new bookings

10. **Welcome to your Website. Enjoy!**
    - Don't forget to edit HTML content on the website to what you want, most are just placeholders from me
    - Keep this `Production_Release_Notes.markdown` file somewhere safe, I will be having a copy too for future usage where necessary


### Future Upgrades Ideas 
- Control the entire admin dashboard and admin functionality from Telegram using commands


### Changelog (For Developers Use)
- Any updates or changes will be updated here post production release


<br>
Thank you for allowing us to work on this project, we absolutely appreciate it. For future upgrades or improvements, do contact me at +65 98293892. 

<br>
Regards, 
<br><br>
Caleb T. <br>
Founder @ NXStudios, a part of NXGroup.