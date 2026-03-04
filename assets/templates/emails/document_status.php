<?php


$statusColors = [
    'approved' => '#28a745',
    'rejected' => '#dc3545',
    'pending'  => '#ffc107',
    'in_progress' => '#007bff',
    'on_hold'  => '#fd7e14',
    'cancelled' => '#6c757d',
];
$color       = $statusColors[strtolower($status ?? '')] ?? '#1259c3';
$statusUpper = strtoupper($status ?? 'PENDING');

$statusIcons = [
    'approved'    => '✅',
    'rejected'    => '❌',
    'pending'     => '⏳',
    'in_progress' => '🔄',
    'on_hold'     => '⚠️',
    'cancelled'   => '🚫',
];
$statusIcon = $statusIcons[strtolower($status ?? '')] ?? '📋';

$statusMessages = [
    'approved'    => 'Your document has been fully approved and is ready for download.',
    'rejected'    => 'Your document was rejected during the approval process. Please review the feedback and resubmit.',
    'pending'     => 'Your document is currently pending review by the assigned approver.',
    'in_progress' => 'Your document is progressing through the approval workflow.',
    'on_hold'     => 'Your document is currently on hold and requires your attention.',
    'cancelled'   => 'Your document submission has been cancelled.',
];
$defaultMsg = $statusMessages[strtolower($status ?? '')] ?? 'There has been an update on your document.';
?>

<!-- Greeting -->
<h2 style='color: #333333; margin: 0 0 20px 0; font-size: 22px; font-weight: 600;'>
    Document Status Update
</h2>
<p style='color: #555555; line-height: 1.6; margin: 0 0 20px 0; font-size: 15px;'>
    Hello <strong><?php echo htmlspecialchars($recipientName ?? ''); ?></strong>,
</p>
<p style='color: #555555; line-height: 1.6; margin: 0 0 25px 0; font-size: 15px;'>
    <?php echo htmlspecialchars($defaultMsg); ?>
</p>

<!-- Document Details Card -->
<table role='presentation' style='width: 100%; border-collapse: collapse; background-color: #f8f9fa; border-radius: 6px; margin: 25px 0; border-left: 4px solid <?php echo $color; ?>;'>
    <tr>
        <td style='padding: 20px;'>
            <h3 style='margin: 0 0 12px 0; color: #333333; font-size: 17px;'>📄 Document Details</h3>
            <p style='margin: 0 0 8px 0; color: #555555; font-size: 14px;'>
                <strong>Title:</strong> <?php echo htmlspecialchars($documentTitle ?? 'N/A'); ?>
            </p>
            <?php if (!empty($message)): ?>
            <p style='margin: 0 0 8px 0; color: #555555; font-size: 14px;'>
                <strong>Note:</strong> <em><?php echo htmlspecialchars($message); ?></em>
            </p>
            <?php endif; ?>
            <p style='margin: 0; color: #555555; font-size: 14px;'>
                <strong>Date:</strong> <?php echo date('F j, Y \a\t g:i A'); ?>
            </p>
        </td>
    </tr>
</table>

<!-- Status Badge Block -->
<table role='presentation' style='width: 100%; border-collapse: collapse; margin: 25px 0;'>
    <tr>
        <td align='center'
            style='background-color: <?php echo $color; ?>; border-radius: 6px; padding: 18px;'>
            <p style='margin: 0; color: #ffffff; font-size: 20px; font-weight: bold;
                       text-transform: uppercase; letter-spacing: 1px;'>
                <?php echo $statusIcon; ?> <?php echo $statusUpper; ?>
            </p>
        </td>
    </tr>
</table>

<!-- Next Steps Block -->
<table role='presentation' style='width: 100%; border-collapse: collapse;
       background-color: #e7f3ff; border-left: 4px solid #007bff;
       border-radius: 4px; margin: 20px 0;'>
    <tr>
        <td style='padding: 15px;'>
            <p style='margin: 0; color: #004085; font-size: 14px; line-height: 1.6;'>
                <?php if (strtolower($status ?? '') === 'approved'): ?>
                    <strong>📋 Next Steps:</strong><br>
                    • Log in to your account to download the approved document<br>
                    • Keep a copy for your records<br>
                    • Contact the office if you need further assistance
                <?php elseif (strtolower($status ?? '') === 'rejected'): ?>
                    <strong>📋 Next Steps:</strong><br>
                    • Review the rejection feedback carefully<br>
                    • Make the necessary revisions to your document<br>
                    • Submit a new document through the Sign-um portal
                <?php elseif (strtolower($status ?? '') === 'on_hold'): ?>
                    <strong>⚠️ Action Required:</strong><br>
                    • Log in to the portal to review the hold reason<br>
                    • Respond or resubmit within the allowed timeframe<br>
                    • Contact your adviser if you need guidance
                <?php else: ?>
                    <strong>📋 What happens next:</strong><br>
                    • The document continues through the approval workflow<br>
                    • You will be notified at each approval stage<br>
                    • Final approval notification will be sent when complete
                <?php endif; ?>
            </p>
        </td>
    </tr>
</table>

<!-- CTA Button -->
<table role='presentation' style='width: 100%; border-collapse: collapse; margin: 30px 0;'>
    <tr>
        <td align='center'>
            <a href='<?php echo (defined("BASE_URL") ? BASE_URL : "#") . "?page=login"; ?>'
               style='background-color: <?php echo $color; ?>; color: #ffffff;
                      padding: 15px 35px; text-decoration: none; border-radius: 50px;
                      font-weight: bold; display: inline-block; font-size: 15px;
                      box-shadow: 0 2px 6px rgba(0,0,0,0.15);'>
                🔗 View Document in Portal
            </a>
        </td>
    </tr>
</table>

<!-- Disclaimer -->
<p style='color: #999999; font-size: 12px; line-height: 1.6; margin: 20px 0 0 0;
          border-top: 1px solid #e9ecef; padding-top: 15px;'>
    This is an automated notification from the Sign-um System. Please do not reply to this email.<br>
    If you did not submit this document or believe this is an error, please contact
    <a href='mailto:support@spcf-signum.com' style='color: #1259c3;'>support@spcf-signum.com</a>.
</p>