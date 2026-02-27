<?php
/**
 * Centralized Mailer Utility
 * ==========================
 * Handles all outbound emails for the system.
 * Automatically wraps message content in the master layout.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    private $mail;

    public function __construct() {
        $this->mail = new PHPMailer(true);
        $this->setupServer();
    }

    /**
     * Configures the SMTP server using .env variables
     */
    private function setupServer() {
        $this->mail->isSMTP();
        $this->mail->Host       = $_ENV['MAIL_HOST'];
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = $_ENV['MAIL_USERNAME'];
        $this->mail->Password   = $_ENV['MAIL_PASSWORD'];
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $this->mail->Port       = $_ENV['MAIL_PORT'];

        $this->mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
        $this->mail->addReplyTo($_ENV['MAIL_FROM_ADDRESS'], 'Support');
        $this->mail->isHTML(true);

        // Always embed the layout's header image
        $imagePath = ROOT_PATH . 'assets/images/Email_background.jpg';
        if (file_exists($imagePath)) {
            $this->mail->addEmbeddedImage($imagePath, 'header_image', 'Email_background.jpg', 'base64', 'image/jpeg');
        }
    }

    /**
     * Sends an email using a specific template
     * * @param string $toEmail Recipient email address
     * @param string $toName Recipient full name
     * @param string $subject Email subject line
     * @param string $templateName Name of the file in templates/emails/ (without .php)
     * @param array  $templateData Associative array of variables to pass to the template
     * @return bool True on success, false on failure
     */
    public function send($toEmail, $toName, $subject, $templateName, $templateData = []) {
        try {
            // Clear recipients in case this instance is reused
            $this->mail->clearAddresses(); 
            $this->mail->addAddress($toEmail, $toName);
            $this->mail->Subject = $subject;

            // Generate HTML body using the layout system
            $this->mail->Body = $this->renderTemplate($templateName, $templateData);
            
            // Generate a plain-text fallback
            $this->mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $this->mail->Body));

            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error: " . $this->mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Compiles the specific view into the master layout
     */
    private function renderTemplate($templateName, $data) {
        // Extract array keys into actual variables (e.g., $user, $code)
        extract($data);

        // 1. Capture the specific email content
        ob_start();
        $templatePath = ROOT_PATH . 'assets/templates/emails/' . $templateName . '.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo "Template file not found.";
        }
        $content = ob_get_clean();

        // 2. Inject it into the master layout
        ob_start();
        include ROOT_PATH . 'assets/templates/emails/layout.php';
        return ob_get_clean();
    }
}