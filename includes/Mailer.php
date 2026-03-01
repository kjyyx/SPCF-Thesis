<?php
/**
 * Centralized Mailer Utility (Production)
 * =======================================
 * Handles all outbound emails for the system using .env credentials.
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
        
        // Dynamically set encryption based on the port in your .env
        $this->mail->SMTPSecure = ($_ENV['MAIL_PORT'] == 587) ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $this->mail->Port       = $_ENV['MAIL_PORT'];

        $this->mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
        $this->mail->addReplyTo($_ENV['MAIL_FROM_ADDRESS'], 'Support');
        $this->mail->isHTML(true);

        // Prevents bulk-sending headers that often trigger spam filters
        $this->mail->XMailer = 'Sign-um Portal';
    }

    /**
     * Sends a targeted notification email
     */
    public function send($toEmail, $toName, $subject, $templateName, $templateData = []) {
        try {
            // Clear recipients in case this instance is reused
            $this->mail->clearAddresses(); 
            $this->mail->addAddress($toEmail, $toName);
            $this->mail->Subject = $subject;

            // Always embed the header logo
            $this->mail->addEmbeddedImage(ROOT_PATH . 'assets/images/Sign-UM logo.png', 'header_image');

            // Generate HTML body using the layout system
            $this->mail->Body = $this->renderTemplate($templateName, $templateData);
            
            // Generate a plain-text fallback for email clients that block HTML
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
        // Extract array keys into actual variables
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