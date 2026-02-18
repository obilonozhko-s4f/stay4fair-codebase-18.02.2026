<?php
/**
 * Owner PDF Template
 *
 * Variables available:
 * @var array $d
 */

$e = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$logo = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.webp';

/* ============================= */
/* Guest address block */
/* ============================= */

$guest_address_lines = [];

if (!empty($d['guest_addr'])) {
    $guest_address_lines[] = $d['guest_addr'];
}

if (!empty($d['guest_zip']) || !empty($d['guest_city'])) {
    $guest_address_lines[] = trim(($d['guest_zip'] ?? '') . ' ' . ($d['guest_city'] ?? ''));
}

if (!empty($d['guest_country'])) {
    $guest_address_lines[] = $d['guest_country'];
} else {
    $guest_address_lines[] = 'Deutschland';
}

$guest_address = implode('<br>', array_map($e, $guest_address_lines));
$owner_name = !empty($d['owner_name']) ? $d['owner_name'] : '—';

?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">

<style>
body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    color: #212F54;
    line-height: 1.45;
}

table { border-collapse: collapse; width: 100%; }

.header td { vertical-align: top; }

.logo img { height: 36px; }

.contact {
    text-align: right;
    font-size: 10.5px;
    color: #555;
}

h1 {
    font-size: 18px;
    margin: 12px 0 6px 0;
}

.subline {
    font-size: 10.5px;
    color: #555;
    margin-bottom: 14px;
}

.box {
    border: 1px solid #D3D7E0;
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 10px;
}

.label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #7A8193;
    margin-bottom: 4px;
}

.value {
    font-size: 12.5px;
    font-weight: 700;
}

.note {
    font-size: 10.5px;
    color: #555;
}
</style>
</head>

<body>

<table class="header">
<tr>
    <td class="logo">
        <img src="<?php echo $e($logo); ?>" alt="Stay4Fair">
    </td>
    <td class="contact">
        <strong>Stay4Fair.com</strong><br>
        Tel / WhatsApp: +49 176 24615269<br>
        E-Mail: business@stay4fair.com<br>
        Owner Portal: stay4fair.com/owner-login/
    </td>
</tr>
</table>

<h1>Buchungsbestätigung (Besitzer) – #<?php echo $e($d['booking_id']); ?></h1>

<div class="subline">
    Business Model: <strong><?php echo $e($d['business_model']); ?></strong>
    · Dokumenttyp: <?php echo $e($d['document_type']); ?>
</div>

<!-- Apartment -->
<div class="box">
    <div class="label">Apartment</div>
    <div class="value">
        <?php echo $e($d['apt_title']); ?> (ID <?php echo $e($d['apt_id']); ?>)
    </div>
    <div class="note" style="margin-top:6px">
        Adresse: <?php echo $e($d['apt_address']); ?><br>
        Vermieter: <strong><?php echo $e($owner_name); ?></strong>
    </div>
</div>

<!-- Zeitraum -->
<div class="box">
    <div class="label">Zeitraum</div>
    <div class="value">
        <?php echo $e($d['check_in']); ?> – <?php echo $e($d['check_out']); ?>
    </div>
    <div class="note" style="margin-top:6px">
        Nächte: <?php echo $e($d['nights']); ?> · Gäste: <?php echo $e($d['guests']); ?>
    </div>
</div>

<!-- Gast -->
<div class="box">
    <div class="label">Gast / Rechnungskontakt</div>
    <div class="note">
        <?php echo $e($d['guest_name']); ?><br>
        <?php if (!empty($d['guest_company'])) : ?>
            Firma: <?php echo $e($d['guest_company']); ?><br>
        <?php endif; ?>
        <?php echo $e($d['guest_email']); ?> · <?php echo $e($d['guest_phone']); ?><br>
        Adresse:<br>
        <?php echo $guest_address ?: '—'; ?>
    </div>
</div>

<!-- NEW: Brutto price -->
<div class="box">
    <div class="label">Brutto-Buchungspreis (Gast)</div>
    <div class="value">
        <?php echo $e($d['guest_gross_total'] ?? '0,00'); ?> €
    </div>
</div>

<!-- Auszahlung -->
<div class="box">
    <div class="label">Auszahlung an Vermieter</div>
    <div class="value"><?php echo $e($d['payout']); ?> €</div>
</div>

<!-- Provision -->
<?php if (!empty($d['pricing'])) : ?>
<div class="box">
    <div class="label">Provision & Vermittlungsgebühr</div>
    <div class="note">
        Provision: <?php echo $e($d['pricing']['commission_rate'] * 100); ?> %<br>
        Provision (netto): <?php echo $e(number_format($d['pricing']['commission_net_total'], 2, ',', '.')); ?> €<br>
        MwSt auf Provision (19%): <?php echo $e(number_format($d['pricing']['commission_vat_total'], 2, ',', '.')); ?> €<br>
        <strong>Provision (brutto): <?php echo $e(number_format($d['pricing']['commission_gross_total'], 2, ',', '.')); ?> €</strong>
    </div>
</div>
<?php endif; ?>

<!-- Steuer Hinweis (freundlich formuliert) -->
<div class="box">
    <div class="label">Auszahlung & steuerliche Hinweise</div>
    <div class="note">
        Die Auszahlung erfolgt in der Regel innerhalb von 3–7 Werktagen nach Abreise des Gastes.<br><br>

        Wir freuen uns über Ihre erfolgreichen Buchungen! Bitte beachten Sie, dass die erzielten Einkünfte aus der kurzfristigen Vermietung steuerpflichtig sind. Die Verantwortung für die korrekte Versteuerung sowie die Einhaltung aller steuerlichen Meldepflichten liegt gemäß den gesetzlichen Vorgaben beim Vermieter.

Ein besonderer Hinweis zum Beherbergungsteuer (City Tax): > Bitte prüfen Sie eigenständig die lokalen Satzungen Ihrer Stadt. In vielen Regionen sind Vermieter verpflichtet, diese Steuer ordnungsgemäß zu erfassen und abzuführen. Da die Handhabung je nach Aufenthaltszweck (geschäftlich oder privat) variieren kann, liegt die finale Prüfung und Abwicklung ausschließlich in Ihrer Hand.

Stay4Fair unterstützt Sie mit der Bereitstellung der Buchungsdaten, übernimmt jedoch keine steuerliche Beratung oder Haftung.
    </div>
</div>

</body>
</html>
