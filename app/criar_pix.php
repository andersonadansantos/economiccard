<?php
require_once 'config.php';
require_once 'asaas_pix.php';
header('Content-Type: application/json');
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Sessão inválida']);
    exit;
}
$uid = (int)$_SESSION['usuario_id'];

$planoId = (int)($_POST['plano_id'] ?? 0);
$plano = $conn->query("SELECT * FROM planos WHERE id = $planoId AND ativo = 1")->fetch_assoc();
if (!$plano) {
    echo json_encode(['status' => 'error', 'message' => 'Plano inválido']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
if (!$u) {
    echo json_encode(['status' => 'error', 'message' => 'Usuário inválido']);
    exit;
}

if ($u['cartao_ativo']) {
    echo json_encode(['status' => 'approved', 'message' => 'Cartão já ativo']);
    exit;
}

// Token do Mercado Pago (fallback legado) — a presença da chave Asaas tem prioridade.
$token = '';
$tr = $conn->query("SELECT access_token FROM api_pagamento WHERE id = 1");
if ($tr && ($row = $tr->fetch_assoc())) { $token = $row['access_token'] ?? ''; }

$stmt = $conn->prepare("UPDATE pagamentos_pix SET status = 'cancelled' WHERE usuario_id = ? AND status = 'pending'");
$stmt->bind_param('i', $uid);
$stmt->execute();

$valor = (float)$plano['valor'];
$dias = (int)$plano['dias'];
$nomePlano = $plano['nome'];
$descricao = "Ativação Economic Card - $nomePlano - $dias dias";

// ---- Caminho preferencial: Asaas (Split de Pagamentos nativo) ----
// Quando a chave Asaas estiver configurada, a cobrança é emitida pela conta da
// empresa com split automático para o parceiro. O Mercado Pago abaixo permanece
// apenas como fallback legado.
if ($cfgAsaas = asaas_config($conn)) {
    $cust = asaas_obter_customer($cfgAsaas, $u);
    if (!$cust['ok']) {
        echo json_encode(['status' => 'error', 'message' => $cust['message']]);
        exit;
    }
    $cob = asaas_criar_cobranca_pix($cfgAsaas, $cust['customer_id'], $valor, $descricao, $uid);
    if (!$cob['ok']) {
        echo json_encode(['status' => 'error', 'message' => $cob['message']]);
        exit;
    }
    $payId = (string)$cob['payment']['id'];
    $qr = asaas_qrcode_pix($cfgAsaas, $payId);
    if (!$qr['ok']) {
        echo json_encode(['status' => 'error', 'message' => $qr['message']]);
        exit;
    }
    $stmt = $conn->prepare("INSERT INTO pagamentos_pix (usuario_id, plano_id, mp_payment_id, valor, descricao, qr_code_base64, qr_code_copia_cola, status, pix_validade, criado_em, provedor, asaas_payment_id) VALUES (?, ?, 0, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW(), 'asaas', ?)");
    $stmt->bind_param('iidsssss', $uid, $planoId, $valor, $descricao, $qr['encoded_image'], $qr['payload'], $payId);
    $stmt->execute();
    $pix = $conn->query("SELECT * FROM pagamentos_pix WHERE id = " . $conn->insert_id)->fetch_assoc();
    echo json_encode([
        'status' => 'pending',
        'provedor' => 'asaas',
        'split_aplicado' => $cob['split_aplicado'],
        'pix' => [
            'id' => (int)$pix['id'],
            'mp_payment_id' => 0,
            'gateway_payment_id' => $payId,
            'qr_code_base64' => $pix['qr_code_base64'],
            'qr_code_copia_cola' => $pix['qr_code_copia_cola'],
            'valor' => $valor,
            'plano' => $nomePlano,
            'dias' => $dias
        ]
    ]);
    exit;
}

// ---- Fallback legado: Mercado Pago ----
if (!$token) {
    echo json_encode(['status' => 'error', 'message' => 'API de pagamento não configurada']);
    exit;
}

$payload = json_encode([
    'transaction_amount' => $valor,
    'description' => $descricao,
    'payment_method_id' => 'pix',
    'payer' => [
        'email' => 'usuario' . $uid . '@economiccard.com.br',
        'first_name' => $u['nome'],
        'identification' => ['type' => 'CPF', 'number' => preg_replace('/\D/', '', $u['cpf'])]
    ]
], JSON_UNESCAPED_UNICODE);

$split = montar_split_pagamento($conn, $valor);
$authToken = $token;
if ($split) {
    $arr = json_decode($payload, true);
    $arr['application_fee'] = $split['application_fee'];
    $payload = json_encode($arr, JSON_UNESCAPED_UNICODE);
    $authToken = $split['mp_access_token'];
}

$ch = curl_init('https://api.mercadopago.com/v1/payments');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $authToken,
        'Content-Type: application/json',
        'X-Idempotency-Key: ' . uniqid('pix', true)
    ],
    CURLOPT_TIMEOUT => 30
]);
$res = curl_exec($ch);
curl_close($ch);
$dados = json_decode($res, true);

if (!empty($dados['id']) && !empty($dados['point_of_interaction']['transaction_data']['qr_code'])) {
    $t = $dados['point_of_interaction']['transaction_data'];
    $stmt = $conn->prepare("INSERT INTO pagamentos_pix (usuario_id, plano_id, mp_payment_id, valor, descricao, qr_code_base64, qr_code_copia_cola, status, pix_validade, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())");
    $stmt->bind_param('iisdsss', $uid, $planoId, $dados['id'], $valor, $descricao, $t['qr_code_base64'], $t['qr_code']);
    $stmt->execute();
    $pix = $conn->query("SELECT * FROM pagamentos_pix WHERE id = " . $conn->insert_id)->fetch_assoc();
    echo json_encode([
        'status' => 'pending',
        'split_aplicado' => (bool)$split,
        'pix' => [
            'id' => (int)$pix['id'],
            'mp_payment_id' => (int)$pix['mp_payment_id'],
            'qr_code_base64' => $pix['qr_code_base64'],
            'qr_code_copia_cola' => $pix['qr_code_copia_cola'],
            'valor' => $valor,
            'plano' => $nomePlano,
            'dias' => $dias
        ]
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => $dados['message'] ?? 'Erro ao gerar o PIX']);
}
