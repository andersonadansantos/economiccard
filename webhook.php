<?php
header('Content-Type: application/json');
http_response_code(200);

require_once __DIR__ . '/app/config.php';

function responder($dados) {
    echo json_encode($dados);
    exit;
}

$mpPaymentId = 0;
$tipo = $_GET['type'] ?? $_GET['topic'] ?? '';
$idNotificado = (int)($_GET['data_id'] ?? $_GET['id'] ?? 0);
if (($tipo === '' || $tipo === 'payment') && $idNotificado > 0) {
    $mpPaymentId = $idNotificado;
}
if (!$mpPaymentId) {
    $corpo = json_decode(file_get_contents('php://input'), true) ?: [];
    $tipoCorpo = $corpo['type'] ?? $corpo['topic'] ?? '';
    if ($tipoCorpo === '' || $tipoCorpo === 'payment') {
        $mpPaymentId = (int)($corpo['data']['id'] ?? 0);
    }
}
if (!$mpPaymentId) {
    responder(['recebido' => true]);
}

$tokenEmpresa = '';
$tr = $conn->query("SELECT access_token FROM api_pagamento WHERE id = 1");
if ($tr && ($linha = $tr->fetch_assoc())) {
    $tokenEmpresa = trim((string)($linha['access_token'] ?? ''));
}
if ($tokenEmpresa === '') {
    error_log('webhook: API de pagamento nao configurada');
    responder(['recebido' => true]);
}

$candidatos = [$tokenEmpresa];
$split = montar_split_pagamento($conn, 0);
if ($split && !in_array($split['mp_access_token'], $candidatos, true)) {
    $candidatos[] = $split['mp_access_token'];
}

$pagamento = [];
foreach ($candidatos as $tk) {
    $ch = curl_init('https://api.mercadopago.com/v1/payments/' . $mpPaymentId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tk],
        CURLOPT_TIMEOUT => 30
    ]);
    $resposta = curl_exec($ch);
    curl_close($ch);
    $pagamento = json_decode((string)$resposta, true) ?: [];
    if (!empty($pagamento['status'])) {
        break;
    }
}
if (empty($pagamento['status'])) {
    error_log('webhook: nao foi possivel consultar o pagamento ' . $mpPaymentId . ' na API do Mercado Pago');
    responder(['recebido' => true]);
}

$status = strtolower((string)$pagamento['status']);
$stmt = $conn->prepare("SELECT id, usuario_id, plano_id, status FROM pagamentos_pix WHERE mp_payment_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i', $mpPaymentId);
$stmt->execute();
$pix = $stmt->get_result()->fetch_assoc();

if ($status === 'approved' && $pix && $pix['status'] !== 'approved') {
    $uid = (int)$pix['usuario_id'];
    require_once __DIR__ . '/app/email_sender.php';
    $dias = 60;
    if (!empty($pix['plano_id'])) {
        $pl = $conn->query("SELECT dias FROM planos WHERE id = " . (int)$pix['plano_id'] . " AND ativo = 1")->fetch_assoc();
        if ($pl) { $dias = (int)$pl['dias']; }
    }
    $validade = date('Y-m-d', strtotime('+' . $dias . ' days'));
    $stmt = $conn->prepare("UPDATE usuarios SET cartao_ativo = 1, cartao_validade = ? WHERE id = ?");
    $stmt->bind_param('si', $validade, $uid);
    $stmt->execute();

    $stmt = $conn->prepare("UPDATE pagamentos_pix SET status = 'approved' WHERE id = ?");
    $stmt->bind_param('i', $pix['id']);
    $stmt->execute();

    if ($conn->affected_rows > 0) {
        $u = $conn->query("SELECT nome, email FROM usuarios WHERE id = $uid")->fetch_assoc();
        if ($u && !empty($u['email'])) {
            enviar_template_geral('cartao_ativado', ['nome' => $u['nome'], 'email' => $u['email']]);
        }
    }
} elseif ($status !== 'approved' && $pix && in_array($pix['status'], ['pending'], true) && in_array($status, ['cancelled', 'rejected', 'refunded', 'charged_back'], true)) {
    $stmt = $conn->prepare("UPDATE pagamentos_pix SET status = ? WHERE id = ?");
    $st = strtolower($status) === 'cancelled' ? 'cancelled' : $status;
    $stmt->bind_param('si', $st, $pix['id']);
    $stmt->execute();
}

responder(['recebido' => true, 'payment_id' => $mpPaymentId, 'status' => $status]);
