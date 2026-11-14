<?php
session_start();


function e(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}


const MODELO_PADRAO = 'gemini-3.5-flash-lite';


const MODELOS_RESERVA = ['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-3.6-flash'];

/** Número máximo de modelos tentados por pergunta. */
const MAX_TENTATIVAS = 3;

/** Tempo máximo (em segundos) esperando a resposta da IA. */
const TEMPO_LIMITE_API = 25;


function mensagemFalhaCurl(int $errno, string $detalhe): string
{
    switch ($errno) {
        case 28:
            $msg = 'A IA demorou demais para responder (limite de ' . TEMPO_LIMITE_API . ' segundos). '
                 . 'Tente de novo ou use um modelo mais rápido (um "flash-lite") em "gemini_model" no config.php.';
            break;
        case 6:
            $msg = 'Não foi possível encontrar o servidor do Google (falha de DNS). '
                 . 'Verifique sua internet ou o DNS da rede.';
            break;
        case 7:
            $msg = 'A conexão com o Google foi recusada ou bloqueada. Verifique firewall, antivírus ou proxy.';
            break;
        case 51: case 58: case 60: case 77: case 83:
            $msg = 'O PHP não conseguiu validar o certificado de segurança do Google (comum no XAMPP). '
                 . 'Baixe https://curl.se/ca/cacert.pem, salve em C:\xampp\php\extras\ssl\cacert.pem, '
                 . 'aponte curl.cainfo para esse arquivo no php.ini e reinicie o Apache.';
            break;
        default:
            $msg = 'Não foi possível conectar ao serviço de IA. Verifique sua internet e tente novamente.';
    }
    return $msg . ' (detalhe técnico: cURL #' . $errno . ' - ' . $detalhe . ')';
}

/**
 * Lê chave e modelo: variáveis de ambiente (GEMINI_API_KEY, GEMINI_MODEL) primeiro,
 * depois o config.php.
 */
function carregarConfig(): array
{
    $config  = [];
    $arquivo = __DIR__ . '/config.php';
    if (is_file($arquivo)) {
        $lido = require $arquivo;
        if (is_array($lido)) {
            $config = $lido;
        }
    }

    $chave  = getenv('GEMINI_API_KEY') ?: (string)($config['gemini_api_key'] ?? '');
    $modelo = trim(getenv('GEMINI_MODEL') ?: (string)($config['gemini_model'] ?? ''));

    // O nome do modelo entra na URL: só aceita letras, números, ponto e hífen.
    if (!preg_match('/^[a-z0-9][a-z0-9.\-]*$/i', $modelo)) {
        $modelo = MODELO_PADRAO;
    }

    return ['chave' => $chave, 'modelo' => $modelo];
}

/**
 * Mostra uma página de erros com o mesmo visual do site.
 * $manter: campos (não sensíveis) que o formulário deve preencher de novo ao voltar.
 * Ficam só no sessionStorage do navegador — nunca vão para a URL. A senha nunca é guardada.
 */
function paginaErros(string $titulo, array $erros, array $manter = []): void
{
    http_response_code(422);
    ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titulo) ?></title>
    <link rel="stylesheet" href="style.css?v=<?= (int)@filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="container-ia">
    <h2><?= e($titulo) ?></h2>
    <ul class="erros">
        <?php foreach ($erros as $erro): ?>
            <li><?= e($erro) ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="Formulario.html">&larr; Voltar e corrigir</a></p>
</div>
<?php if ($manter): ?>
<script>
    try {
        sessionStorage.setItem('cadastroRascunho', JSON.stringify(<?= json_encode($manter, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>));
    } catch (e) {}
</script>
<?php endif; ?>
</body>
</html>
    <?php
    exit;
}

/** Aplica negrito, itálico e código inline (o texto já vem escapado). */
function formatarInline(string $s): string
{
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/', '<em>$1</em>', $s);
    return $s;
}

/**
 * Converte o markdown simples devolvido pela IA em HTML seguro:
 * títulos (#), listas (* - 1.), **negrito**, *itálico*, `código` e parágrafos.
 */
function formatarResposta(string $texto): string
{
    // Escapa primeiro: nada do que a IA devolver vira HTML por conta própria.
    $texto = e(str_replace(["\r\n", "\r"], "\n", $texto));

    $html      = '';
    $paragrafo = [];
    $lista     = null; // 'ul' | 'ol' | null

    $fecharParagrafo = function () use (&$html, &$paragrafo) {
        if ($paragrafo) {
            $html .= '<p>' . implode('<br>', $paragrafo) . "</p>\n";
            $paragrafo = [];
        }
    };
    $fecharLista = function () use (&$html, &$lista) {
        if ($lista) {
            $html .= "</$lista>\n";
            $lista = null;
        }
    };

    foreach (explode("\n", $texto) as $linha) {
        $linha = rtrim($linha);

        if ($linha === '') {
            $fecharParagrafo();
            $fecharLista();
        } elseif (preg_match('/^#{1,6}\s+(.*)$/', $linha, $m)) {
            $fecharParagrafo();
            $fecharLista();
            $html .= '<h4>' . formatarInline($m[1]) . "</h4>\n";
        } elseif (preg_match('/^\s*[\*\-]\s+(.*)$/', $linha, $m)) {
            $fecharParagrafo();
            if ($lista !== 'ul') {
                $fecharLista();
                $html .= "<ul>\n";
                $lista = 'ul';
            }
            $html .= '<li>' . formatarInline($m[1]) . "</li>\n";
        } elseif (preg_match('/^\s*\d+[\.\)]\s+(.*)$/', $linha, $m)) {
            $fecharParagrafo();
            if ($lista !== 'ol') {
                $fecharLista();
                $html .= "<ol>\n";
                $lista = 'ol';
            }
            $html .= '<li>' . formatarInline($m[1]) . "</li>\n";
        } else {
            $fecharLista();
            $paragrafo[] = formatarInline(trim($linha));
        }
    }

    $fecharParagrafo();
    $fecharLista();
    return $html;
}

/**
 * Envia o termo ao Gemini.
 * Retorna ['ok' => true, 'texto' => ...] ou ['ok' => false, 'erro' => mensagem amigável].
 * Detalhes técnicos vão para o log de erros do PHP, nunca para a tela.
 */
function consultarGemini(string $termo, string $apiKey, string $modelo): array
{
    if ($apiKey === '' || $apiKey === 'COLE_SUA_CHAVE_AQUI') {
        return ['ok' => false, 'erro' => 'A chave da API ainda não foi configurada. Edite o arquivo config.php.'];
    }

    $url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelo . ':generateContent';
    $dados = [
        'contents' => [
            ['parts' => [['text' => 'Explique de forma clara e concisa o termo acadêmico: ' . $termo]]],
        ],
    ];

    @set_time_limit(TEMPO_LIMITE_API + 30); // no Windows o limite do PHP conta tempo real

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => TEMPO_LIMITE_API,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey, // no cabeçalho, não na URL (a URL pode ir parar em logs)
        ],
        CURLOPT_POSTFIELDS     => json_encode($dados, JSON_UNESCAPED_UNICODE),
    ]);

    $response = curl_exec($ch);
    $curlErro = curl_error($ch);
    $errno    = curl_errno($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($response === false) {
        error_log('[explicador-ia] Falha de conexão (cURL #' . $errno . '): ' . $curlErro);
        return ['ok' => false, 'erro' => mensagemFalhaCurl($errno, $curlErro), 'repetir' => $errno === 28];
    }

    $result = json_decode($response, true);

    if ($status !== 200 || !is_array($result)) {
        $detalhe = is_array($result) ? ($result['error']['message'] ?? '') : '';
        error_log("[explicador-ia] Resposta HTTP $status da API: $detalhe");

        if (in_array($status, [400, 401, 403], true)) {
            $msg = 'A chave da API parece inválida ou sem permissão. Confira o arquivo config.php.';
        } elseif ($status === 404) {
            $msg = 'O modelo de IA «' . $modelo . '» não foi encontrado (o Google pode tê-lo desativado). '
                 . 'Abra index.php?modelos=1 para ver quais modelos a sua chave pode usar e '
                 . 'ajuste "gemini_model" no config.php.';
        } elseif ($status === 429) {
            $msg = 'O limite de uso da API foi atingido. Aguarde um pouco e tente de novo.';
        } elseif ($status >= 500) {
            $msg = 'O serviço de IA está indisponível ou sobrecarregado agora. Tente novamente em instantes.';
        } else {
            $msg = "Não foi possível obter a resposta da IA (código $status).";
        }
        return ['ok' => false, 'erro' => $msg, 'repetir' => $status === 404 || $status === 429 || $status >= 500];
    }

    if (!empty($result['promptFeedback']['blockReason'])) {
        return ['ok' => false, 'erro' => 'Esse termo foi bloqueado pelos filtros de segurança da IA. Tente outro.'];
    }

    $texto = '';
    foreach ($result['candidates'][0]['content']['parts'] ?? [] as $parte) {
        if (!empty($parte['thought'])) {
            continue; // ignora o "raciocínio interno" dos modelos que o enviam
        }
        $texto .= $parte['text'] ?? '';
    }

    if (trim($texto) === '') {
        error_log('[explicador-ia] Resposta sem texto: ' . substr($response, 0, 500));
        return ['ok' => false, 'erro' => 'A IA não retornou nenhum texto. Tente reformular o termo.'];
    }

    return ['ok' => true, 'texto' => $texto];
}

/**
 * Tenta o modelo principal e, se ele falhar por motivo passageiro, os de reserva.
 * Em caso de sucesso, 'modelo' diz quem respondeu e 'reserva' se não foi o principal.
 */
function consultarComReserva(string $termo, string $apiKey, string $principal): array
{
    $fila = array_slice(array_values(array_unique(array_merge([$principal], MODELOS_RESERVA))), 0, MAX_TENTATIVAS);
    $primeiroErro = null;

    foreach ($fila as $modelo) {
        $r = consultarGemini($termo, $apiKey, $modelo);
        if ($r['ok']) {
            $r['modelo']  = $modelo;
            $r['reserva'] = $modelo !== $principal;
            return $r;
        }
        $primeiroErro = $primeiroErro ?? $r;
        if (empty($r['repetir'])) {
            return $r; // erro que trocar de modelo não resolve (chave inválida, sem internet...)
        }
        error_log('[explicador-ia] Modelo ' . $modelo . ' falhou; tentando o próximo.');
    }

    $primeiroErro['erro'] .= ' Os modelos de reserva também não responderam.';
    return $primeiroErro;
}

/**
 * Lista os modelos de texto do Gemini que a chave pode usar agora.
 * Retorna ['ok' => true, 'modelos' => [...]] ou ['ok' => false, 'erro' => mensagem].
 */
function listarModelos(string $apiKey): array
{
    if ($apiKey === '' || $apiKey === 'COLE_SUA_CHAVE_AQUI') {
        return ['ok' => false, 'erro' => 'A chave da API ainda não foi configurada. Edite o arquivo config.php.'];
    }

    $modelos = [];
    $pagina  = '';

    for ($i = 0; $i < 5; $i++) { // no máximo 5 páginas de resultados
        $url = 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200'
             . ($pagina !== '' ? '&pageToken=' . urlencode($pagina) : '');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => TEMPO_LIMITE_API,
            CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . $apiKey],
        ]);
        $response = curl_exec($ch);
        $curlErro = curl_error($ch);
        $errno    = curl_errno($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($response === false) {
            error_log('[explicador-ia] Falha ao listar modelos (cURL #' . $errno . '): ' . $curlErro);
            return ['ok' => false, 'erro' => mensagemFalhaCurl($errno, $curlErro)];
        }

        $result = json_decode($response, true);
        if ($status !== 200 || !is_array($result)) {
            error_log("[explicador-ia] Listagem de modelos: HTTP $status");
            return ['ok' => false, 'erro' => "Não foi possível listar os modelos (código $status). Confira a chave no config.php."];
        }

        foreach ($result['models'] ?? [] as $m) {
            $nome = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
            $metodos = $m['supportedGenerationMethods'] ?? [];
            // Só modelos Gemini de texto (sem imagem, áudio, voz, tempo real ou robótica)
            if (in_array('generateContent', $metodos, true)
                && preg_match('/^gemini-.*(flash|pro)/', $nome)
                && !preg_match('/(image|tts|audio|live|robotics|computer|embedding)/', $nome)) {
                $modelos[] = $nome;
            }
        }

        $pagina = (string)($result['nextPageToken'] ?? '');
        if ($pagina === '') {
            break;
        }
    }

    $modelos = array_values(array_unique($modelos));
    sort($modelos);
    return ['ok' => true, 'modelos' => $modelos];
}

// ======================
// SAIR (encerra a sessão)
// ======================
if (isset($_GET['sair'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: Formulario.html');
    exit;
}

// ======================
// 1️⃣ PROCESSAMENTO DO CADASTRO
// ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario_nome'])) {
    $nome      = trim($_POST['usuario_nome'] ?? '');
    $sobrenome = trim($_POST['usuario_sobrenome'] ?? '');
    $email     = trim($_POST['usuario_email'] ?? '');
    $data_nasc = trim($_POST['usuario_data_nascimento'] ?? '');
    $senha     = $_POST['usuario_senha'] ?? '';
    $repetir   = $_POST['usuario_repetir_senha'] ?? '';
    $aceitou   = $_POST['aceitou_termos'] ?? null;

    $erros = [];

    if ($nome === '') {
        $erros[] = 'Nome é obrigatório.';
    } elseif (mb_strlen($nome) > 100) {
        $erros[] = 'Nome deve ter no máximo 100 caracteres.';
    }

    if ($sobrenome === '') {
        $erros[] = 'Sobrenome é obrigatório.';
    } elseif (mb_strlen($sobrenome) > 100) {
        $erros[] = 'Sobrenome deve ter no máximo 100 caracteres.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'E-mail inválido.';
    }

    if ($data_nasc === '') {
        $erros[] = 'Data de nascimento é obrigatória.';
    } else {
        $dt = DateTime::createFromFormat('!Y-m-d', $data_nasc);
        if (!$dt || $dt->format('Y-m-d') !== $data_nasc) {
            $erros[] = 'Data de nascimento inválida.';
        } elseif ($dt > new DateTime('today')) {
            $erros[] = 'A data de nascimento não pode ser no futuro.';
        }
    }

    if (strlen($senha) < 6) {
        $erros[] = 'Senha deve ter pelo menos 6 caracteres.';
    }
    if ($senha !== $repetir) {
        $erros[] = 'As senhas não coincidem.';
    }
    if ($aceitou !== 'on' && $aceitou !== '1') {
        $erros[] = 'Você deve aceitar os termos.';
    }

    if (!empty($erros)) {
        paginaErros('Erros encontrados', $erros, [
            'nome'      => $nome,
            'sobrenome' => $sobrenome,
            'email'     => $email,
            'data_nasc' => $data_nasc,
        ]);
    }

    // Simulação de cadastro: salva apenas em sessão.
    // Ao ligar um banco de dados, grave password_hash($senha, PASSWORD_DEFAULT) —
    // nunca a senha em texto puro.
    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'nome'      => $nome,
        'sobrenome' => $sobrenome,
        'email'     => $email,
    ];
    $_SESSION['boas_vindas'] = true;

    header('Location: index.php');
    exit;
}

// ======================
// 2️⃣ CHAT / EXPLICADOR IA (só para quem se cadastrou)
// ======================
if (empty($_SESSION['usuario'])) {
    header('Location: Formulario.html');
    exit;
}

// Mensagem de boas-vindas: aparece uma única vez após o cadastro.
$mostrarBoasVindas = !empty($_SESSION['boas_vindas']);
unset($_SESSION['boas_vindas']);

// Token anti-CSRF: impede que outro site dispare consultas (e gaste sua cota) em seu nome.
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$termo      = '';
$respostaIA = '';
$erroIA     = '';
$notaModelo = '';

// Página de diagnóstico: index.php?modelos=1 (só para quem está logado)
$modoModelos = isset($_GET['modelos']);
$listaModelos = [];
$modeloAtual  = '';
if ($modoModelos) {
    $cfg          = carregarConfig();
    $modeloAtual  = $cfg['modelo'];
    $listaModelos = listarModelos($cfg['chave']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['termo'])) {
    $termo = trim($_POST['termo']);

    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $erroIA = 'Sessão expirada. Recarregue a página e tente novamente.';
    } elseif ($termo === '') {
        $erroIA = 'Digite um termo para explicar.';
    } elseif (mb_strlen($termo) > 200) {
        $erroIA = 'O termo deve ter no máximo 200 caracteres.';
    } else {
        $cfg       = carregarConfig();
        $resultado = consultarComReserva($termo, $cfg['chave'], $cfg['modelo']);
        if ($resultado['ok']) {
            $respostaIA = $resultado['texto'];
            $notaModelo = $resultado['reserva'] ? $resultado['modelo'] : '';
        } else {
            $erroIA = $resultado['erro'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explicador Acadêmico IA</title>
    <link rel="stylesheet" href="style.css?v=<?= (int)@filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>

<div class="container-ia">
    <?php if ($mostrarBoasVindas): ?>
        <p class="sucesso">
            ✅ Cadastro realizado com sucesso! Bem-vindo,
            <?= e($_SESSION['usuario']['nome']) ?>!
        </p>
    <?php endif; ?>

    <?php if ($modoModelos): ?>
        <h2>Modelos disponíveis</h2>
        <?php if ($listaModelos['ok']): ?>
            <p>Modelos Gemini de texto que a sua chave pode usar agora.
               Em uso: <code><?= e($modeloAtual) ?></code></p>
            <?php if ($listaModelos['modelos']): ?>
                <ul class="modelos">
                    <?php foreach ($listaModelos['modelos'] as $m): ?>
                        <li><code><?= e($m) ?></code><?= $m === $modeloAtual ? ' ← em uso' : '' ?></li>
                    <?php endforeach; ?>
                </ul>
                <p>Para trocar, edite a linha <code>gemini_model</code> no <code>config.php</code>
                   (ou adicione-a, se não existir) com um dos nomes acima.</p>
            <?php else: ?>
                <div class="resposta erro-ia"><p>Nenhum modelo de texto foi encontrado para essa chave.</p></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="resposta erro-ia" role="alert"><p><?= e($listaModelos['erro']) ?></p></div>
        <?php endif; ?>
        <p><a href="index.php">&larr; Voltar ao explicador</a></p>
    <?php else: ?>
    <h2>Explicador de Termos Acadêmicos com IA</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <label for="termo">Digite um termo acadêmico:</label>
        <input type="text" id="termo" name="termo" maxlength="200" value="<?= e($termo) ?>" required>
        <button type="submit" id="btn-explicar">Explicar</button>
    </form>
    <script>
        // Mostra que a pergunta foi enviada (a IA pode levar alguns segundos)
        document.querySelector('.container-ia form').addEventListener('submit', function () {
            var b = document.getElementById('btn-explicar');
            b.disabled = true;
            b.textContent = 'Explicando...';
        });
    </script>

    <?php if ($erroIA): ?>
        <div class="resposta erro-ia" role="alert">
            <p><?= e($erroIA) ?></p>
        </div>
    <?php elseif ($respostaIA): ?>
        <div class="resposta">
            <h3>Resposta da IA:</h3>
            <?= formatarResposta($respostaIA) ?>
            <?php if ($notaModelo): ?>
                <p class="nota-modelo">Respondido pelo modelo de reserva <code><?= e($notaModelo) ?></code>, porque o principal estava indisponível.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; /* fim do modo chat */ ?>

    <p class="sair"><a href="index.php?sair=1">Sair</a></p>
</div>

</body>
</html>