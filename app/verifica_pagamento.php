<?php
require_once 'config.php';
require_once 'asaas_pix.php';
header('Content-Type: application/json');
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Sessão inválida']);
    exit;
}
$uid = (int)$_SESSION['usuario_id'];
$idParam = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$idParam) {
    echo json_encode(['status' => 'error', 'message' => 'Pagamento inválido']);
    exit;
}

// O ID recebido é o id LOCAL da tabela pagamentos_pix. Se a linha não existir,
// mantém o comportamento antigo (trata o valor como ID direto do Mercado Pago).
$stmt = $conn->prepare("SELECT * FROM pagamentos_pix WHERE id = ? AND usuario_id = ?");
$stmt->bind_param('ii', $idParam, $uid);
$stmt->execute();
$pixRow = $stmt->get_result()->fetch_assoc();

if ($pixRow && ($pixRow['provedor'] ?? 'mp') === 'asaas') {
    // ---- Asaas ----
    $cfg = asaas_config($conn);
    if (!$cfg) {
        echo json_encode(['status' => 'pending']);
        exit;
    }
    $statusAsaas = asaas_status_pagamento($cfg, $pixRow['asaas_payment_id']);
    $status = $statusAsaas !== '' ? asaas_status_local($statusAsaas) : 'pending';
} else {
    // ---- Mercado Pago (legado) ----
    $tr = $conn->query("SELECT access_token FROM api_pagamento WHERE id = 1");
    $token = '';
    if ($tr && ($row = $tr->fetch_assoc())) { $token = $row['access_token'] ?? ''; }
    if (!$token) {
        echo json_encode(['status' => 'pending']);
        exit;
    }

    // Com split ativo, o pagamento foi criado com o token OAuth do parceiro.
    // Consulta primeiro com o token da empresa e, se não encontrar, repete com o do parceiro.
    $candidatos = [$token];
    $split = montar_split_pagamento($conn, 0);
    if ($split) {
        $candidatos[] = $split['mp_access_token'];
    }

    $mpPaymentId = $pixRow ? (int)$pixRow['mp_payment_id'] : $idParam;
    $status = 'pending';
    foreach ($candidatos as $tk) {
        $ch = curl_init('https://api.mercadopago.com/v1/payments/' . $mpPaymentId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tk],
            CURLOPT_TIMEOUT => 30
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $dados = json_decode($res, true) ?: [];
        if (!empty($dados['status'])) {
            $status = strtolower((string)$dados['status']);
            break;
        }
    }
}

if ($status === 'approved') {
    require_once 'email_sender.php';
    $dias = 60;
    $planoIdPix = $pixRow ? (int)($pixRow['plano_id'] ?? 0) : 0;
    if ($planoIdPix) {
        $pl = $conn->query("SELECT dias FROM planos WHERE id = " . $planoIdPix . " AND ativo = 1")->fetch_assoc();
        if ($pl) { $dias = (int)$pl['dias']; }
    }
    $validade = date('Y-m-d', strtotime('+' . $dias . ' days'));
    $stmt = $conn->prepare("UPDATE usuarios SET cartao_ativo = 1, cartao_validade = ? WHERE id = ?");
    $stmt->bind_param('si', $validade, $uid);
    $stmt->execute();

    if ($pixRow) {
        $stmt = $conn->prepare("UPDATE pagamentos_pix SET status = 'approved' WHERE id = ? AND usuario_id = ? AND status <> 'approved'");
        $stmt->bind_param('ii', $pixRow['id'], $uid);
    } else {
        $stmt = $conn->prepare("UPDATE pagamentos_pix SET status = 'approved' WHERE mp_payment_id = ? AND usuario_id = ?");
        $stmt->bind_param('ii', $idParam, $uid);
    }
    $stmt->execute();

    if ($conn->affected_rows > 0) {
        $u = $conn->query("SELECT nome, email FROM usuarios WHERE id = $uid")->fetch_assoc();
        if ($u && !empty($u['email'])) {
            enviar_template_geral('cartao_ativado', ['nome' => $u['nome'], 'email' => $u['email']]);
        }
    }
}

echo json_encode(['status' => $status]);
