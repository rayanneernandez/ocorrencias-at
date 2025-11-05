<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// Configurações de upload para arquivos muito grandes
ini_set('upload_max_filesize', '1G');
ini_set('post_max_size', '1G');
ini_set('max_execution_time', 600);
ini_set('max_input_time', 600);
ini_set('memory_limit', '1G');
ini_set('max_file_uploads', 50);

require_once __DIR__ . '/../includes/db.php';
$pdo = get_pdo();

// Captura a categoria selecionada
$selectedCategory = $_GET['categoryId'] ?? '';

// Registra o clique na categoria no banco de dados
if (!empty($selectedCategory)) {
    $userId = intval($_SESSION['usuario_id'] ?? 0);
    if ($userId > 0) {
        try {
            // Cria a tabela se não existir
            $pdo->exec("CREATE TABLE IF NOT EXISTS category_clicks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                category_id VARCHAR(50) NOT NULL,
                clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_category (user_id, category_id),
                INDEX idx_clicked_at (clicked_at)
            )");
            
            $stmt = $pdo->prepare("INSERT INTO category_clicks (user_id, category_id, clicked_at) VALUES (?, ?, NOW())");
            $stmt->execute([$userId, $selectedCategory]);
        } catch (Exception $e) {
            // Log do erro se necessário, mas não interrompe o fluxo
            error_log("Erro ao registrar clique na categoria: " . $e->getMessage());
        }
    }
}

// Inicializa sessão para armazenar dados entre passos
if(!isset($_SESSION['report'])) {
    $_SESSION['report'] = [
        'step' => 1,
        'address' => '',
        'cep' => '',
        'type' => $selectedCategory, // Pré-seleciona a categoria clicada
        'description' => '',
        'coordinates' => [-22.9068, -43.1729],
        'files' => []
    ];
} else {
    // Se já existe uma sessão de report, atualiza apenas a categoria se foi passada
    if (!empty($selectedCategory)) {
        $_SESSION['report']['type'] = $selectedCategory;
    }
}

$step = $_SESSION['report']['step'];

// Processa POST
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Passo atual enviado no formulário
    $postedStep = isset($_POST['step']) ? intval($_POST['step']) : $step;

    // Navegação para trás: não valida, apenas retorna um passo ou para o início
    if (isset($_POST['navigate']) && $_POST['navigate'] === 'back') {
        if ($postedStep === 1) {
            // Se estiver no passo 1, volta para o dashboard
            unset($_SESSION['report']);
            header('Location: dashboard.php');
            exit;
        } else {
            // Se estiver em outros passos, volta um passo
            $step = max(1, $postedStep - 1);
        }
    } else {
        if ($postedStep === 1) {
            // Validação dos campos obrigatórios
            $errors = [];
            
            if (empty($_POST['type'])) {
                $errors[] = 'Selecione uma categoria';
            }
            
            if (empty($_POST['address'])) {
                $errors[] = 'Informe o endereço';
            }
            
            if (!empty($errors)) {
                $_SESSION['flash_error'] = implode(', ', $errors);
            } else {
                $_SESSION['report']['type'] = $_POST['type'];
                $_SESSION['report']['address'] = $_POST['address'] ?? '';
                $_SESSION['report']['cep'] = $_POST['cep'] ?? '';
                if (isset($_POST['coordinates'])) {
                    $_SESSION['report']['coordinates'] = explode(',', $_POST['coordinates']);
                }
                $step = 2;
            }
        } elseif ($postedStep === 2) {
            // Validação dos campos obrigatórios
            $errors = [];
            
            // Debug: verificar se os dados estão chegando
            error_log("POST data: " . print_r($_POST, true));
            error_log("FILES data: " . print_r($_FILES, true));
            
            if (empty($_POST['type'])) {
                $errors[] = 'Tipo de manifestação é obrigatório';
            }
            
            if (empty($_POST['description']) || trim($_POST['description']) === '') {
                $errors[] = 'Descrição é obrigatória';
            }
            
            // Verificar se há arquivos válidos
            $hasValidFiles = false;
            if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
                foreach ($_FILES['files']['name'] as $i => $name) {
                    if (!empty($name) && !empty($_FILES['files']['tmp_name'][$i])) {
                        $hasValidFiles = true;
                        break;
                    }
                }
            }
            
            if (!$hasValidFiles) {
                $errors[] = 'Pelo menos um arquivo (imagem ou vídeo) é obrigatório';
            }
            
            if (!empty($errors)) {
                $_SESSION['flash_error'] = implode(', ', $errors);
                error_log("Validation errors: " . implode(', ', $errors));
            } else {
                $_SESSION['report']['type'] = $_POST['type'] ?? '';
                $_SESSION['report']['description'] = $_POST['description'] ?? '';
                
                // Salva arquivos em pasta temporária pública para pré-visualização
                $filesOut = [];
                if (!empty($_FILES['files']['name'][0])) {
                    $previewDir = __DIR__ . '/../uploads/temp';
                    if (!is_dir($previewDir)) { @mkdir($previewDir, 0777, true); }
                    
                    foreach ($_FILES['files']['name'] as $i => $name) {
                        $tmp  = $_FILES['files']['tmp_name'][$i] ?? '';
                        $type = $_FILES['files']['type'][$i] ?? '';
                        $size = $_FILES['files']['size'][$i] ?? 0;
                        
                        // Verifica tamanho do arquivo (máximo 500MB por arquivo)
                        if ($size > 500 * 1024 * 1024) {
                            $_SESSION['flash_error'] = "Arquivo '$name' é muito grande. Máximo 500MB por arquivo.";
                            continue;
                        }
                        
                        if ($tmp && is_uploaded_file($tmp)) {
                            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                            $safeName = uniqid('preview_', true) . ($ext ? '.' . $ext : '');
                            $destPath = $previewDir . DIRECTORY_SEPARATOR . $safeName;
                            if (@move_uploaded_file($tmp, $destPath)) {
                                $filesOut[] = [
                                    'name' => $name,
                                    'type' => $type,
                                    'url'  => 'uploads/temp/' . $safeName, // URL pública
                                ];
                            }
                        }
                    }
                }
                $_SESSION['report']['preview_files'] = $filesOut;
                
                if (empty($_SESSION['flash_error'])) {
                    $step = 3;
                }
            }
        } else {
            // Finalização (Passo 3) — salva no banco e vai para Dashboard
            try {

                // Prepara dados para inserção
                $userId = intval($_SESSION['usuario_id'] ?? 0);
                if ($userId <= 0) {
                    throw new Exception('Usuário não está logado');
                }

                $report = $_SESSION['report'];
                if (empty($report)) {
                    throw new Exception('Dados da ocorrência não encontrados na sessão');
                }

                $endereco = $report['address'] ?? '';
                $cep = $report['cep'] ?? '';
                $tipo = $report['type'] ?? '';
                $descricao = $report['description'] ?? '';
                $coords = $report['coordinates'] ?? [0, 0];
                $latitude = is_array($coords) && count($coords) >= 2 ? floatval($coords[0]) : 0;
                $longitude = is_array($coords) && count($coords) >= 2 ? floatval($coords[1]) : 0;
                
                // Validações básicas
                if (empty($tipo)) {
                    throw new Exception('Tipo de ocorrência não especificado');
                }
                if (empty($descricao)) {
                    throw new Exception('Descrição da ocorrência não especificada');
                }
                
                // Gera número único da ocorrência
                $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(numero, 8) AS UNSIGNED)) as max_num FROM ocorrencias WHERE numero LIKE 'OC" . date('Y') . "%'");
                $maxNum = $stmt->fetch(PDO::FETCH_ASSOC)['max_num'] ?? 0;
                $nextNum = $maxNum + 1;
                $numeroOcorrencia = 'OC' . date('Y') . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
                
                error_log("Gerando número de ocorrência: $numeroOcorrencia (último número: $maxNum)");
                
                // Prepara diretórios de upload
                $uploadDir = __DIR__ . '/../uploads';
                $tempDir = __DIR__ . '/../uploads/temp';
                
                // Garante que os diretórios existem
                foreach ([$uploadDir, $tempDir] as $dir) {
                    if (!is_dir($dir)) {
                        if (!mkdir($dir, 0777, true)) {
                            error_log("Erro ao criar diretório: $dir");
                            throw new Exception("Falha ao criar diretório: " . basename($dir));
                        }
                    }
                }
                
                // Processa arquivos
                $arquivosFinal = [];
                $temImagens = 'Não';
                $previewFiles = $report['preview_files'] ?? [];
                
                error_log("Preview files: " . print_r($previewFiles, true));
                
                if (empty($previewFiles)) {
                    throw new Exception('Nenhum arquivo foi enviado');
                }
                
                foreach ($previewFiles as $file) {
                    if (empty($file['url'])) {
                        error_log("URL do arquivo vazio");
                        continue;
                    }
                    
                    // Ajusta o caminho do arquivo temporário
                    $tempPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file['url']);
                    error_log("Processando arquivo: " . $file['name'] . " (temp: $tempPath)");
                    
                    if (!file_exists($tempPath)) {
                        error_log("Arquivo temporário não encontrado: $tempPath");
                        continue;
                    }
                    
                    // Verifica se o arquivo é realmente um arquivo
                    if (!is_file($tempPath)) {
                        error_log("Caminho não é um arquivo: $tempPath");
                        continue;
                    }
                    
                    // Verifica se o arquivo pode ser lido
                    if (!is_readable($tempPath)) {
                        error_log("Arquivo não pode ser lido: $tempPath");
                        continue;
                    }
                    
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (empty($ext)) {
                        error_log("Extensão do arquivo não encontrada: " . $file['name']);
                        continue;
                    }
                    
                    $finalName = $numeroOcorrencia . '_' . uniqid() . '.' . $ext;
                    $finalPath = $uploadDir . DIRECTORY_SEPARATOR . $finalName;
                    
                    error_log("Tentando copiar de $tempPath para $finalPath");
                    
                    // Tenta copiar o arquivo
                    if (!@copy($tempPath, $finalPath)) {
                        $error = error_get_last();
                        error_log("Erro ao copiar arquivo: " . ($error['message'] ?? 'Erro desconhecido'));
                        
                        // Tenta mover como alternativa
                        if (!@rename($tempPath, $finalPath)) {
                            $error = error_get_last();
                            error_log("Erro ao mover arquivo: " . ($error['message'] ?? 'Erro desconhecido'));
                            continue;
                        }
                    } else {
                        // Se copiou com sucesso, remove o arquivo temporário
                        @unlink($tempPath);
                    }
                    
                    // Verifica se o arquivo final existe
                    if (!file_exists($finalPath)) {
                        error_log("Arquivo final não existe após cópia/movimentação: $finalPath");
                        continue;
                    }
                    
                    $arquivosFinal[] = [
                        'name' => $file['name'],
                        'type' => $file['type'],
                        'url' => 'uploads/' . $finalName
                    ];
                    
                    // Verifica se é imagem
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file['name'])) {
                        $temImagens = 'Sim';
                    }
                    
                    error_log("Arquivo processado com sucesso: " . $finalName);
                }
                
                if (empty($arquivosFinal)) {
                    throw new Exception('Nenhum arquivo foi processado com sucesso. Verifique se os arquivos foram enviados corretamente.');
                }
                
                error_log("Arquivos processados com sucesso: " . print_r($arquivosFinal, true));
                
                error_log("Total de arquivos processados: " . count($arquivosFinal));
                
                // Combina o endereço com o número
                $enderecoCompleto = $endereco;
                if (!empty($report['number'])) {
                    $enderecoCompleto .= ', ' . $report['number'];
                }
                
                // Inicia transação
                $pdo->beginTransaction();
                
                try {
                    // Insere ocorrência
                    $insertSQL = "INSERT INTO ocorrencias (
                        numero, 
                        usuario_id, 
                        endereco, 
                        cep, 
                        tipo, 
                        descricao, 
                        latitude, 
                        longitude, 
                        arquivos, 
                        tem_imagens, 
                        status,
                        data_criacao
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Em Análise', NOW())";
                    
                    $stmt = $pdo->prepare($insertSQL);
                    $result = $stmt->execute([
                        $numeroOcorrencia,
                        $userId,
                        $enderecoCompleto,
                        $cep,
                        $tipo,
                        $descricao,
                        $latitude,
                        $longitude,
                        json_encode($arquivosFinal),
                        $temImagens
                    ]);
                    
                    if (!$result) {
                        throw new Exception("Erro ao inserir ocorrência: " . implode(" ", $stmt->errorInfo()));
                    }
                    
                    $ocorrenciaId = $pdo->lastInsertId();
                    
                    // Insere prioridade
                    $sqlPrioridades = "INSERT INTO prioridades (id_ocorrencia, tipo, nivel_prioridade) VALUES (?, ?, ?)";
                    $stmtPrioridades = $pdo->prepare($sqlPrioridades);
                    $resultPrioridades = $stmtPrioridades->execute([
                        $ocorrenciaId,
                        $tipo,
                        2 // Nível de prioridade normal
                    ]);
                    
                    if (!$resultPrioridades) {
                        throw new Exception("Erro ao registrar prioridade: " . implode(" ", $stmtPrioridades->errorInfo()));
                    }
                    
                    // Confirma transação
                    $pdo->commit();
                    
                    error_log("Ocorrência e prioridade registradas com sucesso. ID: " . $ocorrenciaId);
                    $_SESSION['flash_success'] = "Ocorrência $numeroOcorrencia cadastrada com sucesso!";
                    unset($_SESSION['report']); // limpa o estado do wizard
                    session_write_close();
                    header('Location: dashboard.php?success=ocorrencia');
                    exit;
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    // Remove arquivos que foram copiados em caso de erro
                    foreach ($arquivosFinal as $arquivo) {
                        $path = __DIR__ . '/../' . $arquivo['url'];
                        if (file_exists($path)) {
                            @unlink($path);
                        }
                    }
                    throw $e;
                }
                
            } catch (Exception $e) {
                error_log("Erro ao salvar ocorrência: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $_SESSION['flash_error'] = 'Erro ao cadastrar ocorrência: ' . $e->getMessage();
            }
        }
    }

    $_SESSION['report']['step'] = $step;
}

// Dados atuais
$data = $_SESSION['report'];

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registrar Ocorrência</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous" />
<script defer src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>

<?php if (isset($_SESSION['flash_error'])): ?>
<div class="fixed top-4 right-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded z-50" role="alert">
  <strong class="font-bold">Erro!</strong>
  <span class="block sm:inline"><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
</div>
<?php unset($_SESSION['flash_error']); endif; ?>

<?php if (isset($_SESSION['flash_success'])): ?>
<div class="fixed top-4 right-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded z-50" role="alert">
  <strong class="font-bold">Sucesso!</strong>
  <span class="block sm:inline"><?= htmlspecialchars($_SESSION['flash_success']) ?></span>
</div>
<?php unset($_SESSION['flash_success']); endif; ?>

<script>
<?php if ($step === 1): ?>
document.addEventListener('DOMContentLoaded', () => {
    const mapEl = document.getElementById('map');
    const coordsEl = document.getElementById('coordinates');
    const cepEl = document.getElementById('cep');
    const addressEl = document.getElementById('address');

    const DEFAULT = [-22.9068, -43.1729];
    let map, marker;
    let lastRequestId = 0;
    let currentAbort = null;
    let refineWatchId = null;
    let lastCepFetchedDigits = '';   // evita requisição repetida
    let lastAddressQuery = '';      // evita requisição repetida de endereço

  // Função para atualizar o mapa com novo endereço
  async function updateMapFromAddress(address) {
    try {
      const nominatimUrl = `/radci/api/geocode.php?q=${encodeURIComponent(address)}&limit=1`;
      const nominatimRes = await fetch(nominatimUrl);
      const nominatimData = await nominatimRes.json();
      
      if (nominatimData && nominatimData.length > 0) {
        const location = nominatimData[0];
        await applyPosition(parseFloat(location.lat), parseFloat(location.lon), {reverse: true});
        return true;
      }
    } catch (error) {
      console.error('Erro ao geocodificar:', error);
    }
    return false;
  }

  // Monitora mudanças no CEP
  cepEl?.addEventListener('input', async function() {
    const cep = this.value.replace(/\D/g, '');
    if (cep.length === 8) {
      try {
        const viacepUrl = `/radci/api/viacep.php?cep=${cep}`;
        const response = await fetch(viacepUrl);
        const data = await response.json();
        
        if (!data.erro) {
          const fullAddress = `${data.logradouro}, ${data.bairro}, ${data.localidade} - ${data.uf}`;
          if (addressEl) {
            addressEl.value = fullAddress;
            await geocodeAddress(fullAddress);
          }
        }
      } catch (error) {
        // Não mostra erro se falhar
      }
    }
  });

  // Monitora mudanças no campo de endereço
  addressEl?.addEventListener('blur', async function() {
    if (this.value.trim()) {
      await updateMapFromAddress(this.value);
    }
  });

  // ÚNICA definição de newRequest
  function newRequest() {
      if (currentAbort) { try { currentAbort.abort(); } catch(_) {} }
      currentAbort = new AbortController();
      lastRequestId++;
      return { id: lastRequestId, signal: currentAbort.signal };
  }

  // Helpers de normalização (texto e UF) e perímetro
  function norm(s) {
    return String(s || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
  }
  function toUF(s) {
    const n = norm(s);
    if (!n) return '';
    if (n.length === 2) return n;
    const map = {
      'acre':'ac','alagoas':'al','amapa':'ap','amazonas':'am','bahia':'ba','ceara':'ce',
      'distrito federal':'df','df':'df','espirito santo':'es','goias':'go','maranhao':'ma',
      'mato grosso':'mt','mato grosso do sul':'ms','minas gerais':'mg','para':'pa','paraiba':'pb',
      'parana':'pr','pernambuco':'pe','piaui':'pi','rio de janeiro':'rj','rio grande do norte':'rn',
      'rio grande do sul':'rs','rondonia':'ro','roraima':'rr','santa catarina':'sc','sao paulo':'sp',
      'sergipe':'se','tocantins':'to'
    };
    return map[n] || n.slice(0,2);
  }
  function isWithinBounds(lat, lon, bounds) {
    if (!bounds) return true;
    return Number.isFinite(lat) && Number.isFinite(lon) &&
           lon >= bounds.left && lon <= bounds.right &&
           lat >= bounds.bottom && lat <= bounds.top;
  }

  // NOVO: controle de requisições assíncronas
  let reqCounter = 0;
  function newRequest() {
    const controller = new AbortController();
    const id = ++reqCounter;
    lastRequestId = id;
    return { id, signal: controller.signal };
  }

  // NOVO: wrappers de geolocalização
  async function getCurrentPositionWithTimeout(timeoutMs = 8000) {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        reject(new Error('Geolocalização não suportada'));
        return;
      }

      let watchId = null;
      let timeoutId = null;
      let bestPosition = null;

      // Função para limpar os timers e watches
      const cleanup = () => {
        if (watchId !== null) {
          navigator.geolocation.clearWatch(watchId);
          watchId = null;
        }
        if (timeoutId !== null) {
          clearTimeout(timeoutId);
          timeoutId = null;
        }
      };

      // Função para resolver com a melhor posição
      const resolveWithPosition = (position) => {
        cleanup();
        resolve(position);
      };

      // Configura o timeout
      timeoutId = setTimeout(() => {
        cleanup();
        if (bestPosition) {
          resolve(bestPosition);
        } else {
          // Se não tiver nenhuma posição, tenta uma última vez com configurações básicas
          navigator.geolocation.getCurrentPosition(
            resolve,
            reject,
            { 
              enableHighAccuracy: false,
              timeout: 3000,
              maximumAge: 30000
            }
          );
        }
      }, timeoutMs);

      // Função que observa atualizações de posição
      const handlePosition = (position) => {
        // Atualiza a melhor posição se for mais precisa
        if (!bestPosition || position.coords.accuracy < bestPosition.coords.accuracy) {
          bestPosition = position;
          
          // Se a precisão for boa o suficiente, resolve imediatamente
          if (position.coords.accuracy <= 100) {
            resolveWithPosition(position);
          }
        }
      };

      // Inicia o watch com alta precisão
      watchId = navigator.geolocation.watchPosition(
        handlePosition,
        (error) => {
          cleanup();
          // Se falhar o watch, tenta uma vez com getCurrentPosition
          navigator.geolocation.getCurrentPosition(
            resolve,
            reject,
            { 
              enableHighAccuracy: false,
              timeout: 3000,
              maximumAge: 30000
            }
          );
        },
        {
          enableHighAccuracy: true,
          timeout: timeoutMs,
          maximumAge: 0
        }
      );
    });
  }

  // Atualiza a posição do mapa e do marcador
  async function applyPosition(lat, lng, opts = {}) {
    if (!map) {
      createMap([lat, lng]);
    } else {
      marker.setLatLng([lat, lng]);
      map.setView([lat, lng], map.getZoom());
    }
    setCoords(lat, lng);
    
    // Só faz o reverse se for explicitamente solicitado
    if (opts.reverse === true) {
      await doReverse(lat, lng);
    }
  }

  // Reverse geocoding via API local com tratamento de erros aprimorado
  async function doReverse(lat, lng) {
    if (!lat || !lng || !Number.isFinite(lat) || !Number.isFinite(lng)) {
      throw new Error('Coordenadas inválidas');
    }

    try {
      coordsEl.value = `${lat},${lng}`;
      
      // Não faz nada se já tiver um endereço válido
      const currentAddress = addressEl.value.trim();
      if (currentAddress && currentAddress.includes(',')) {
        return;
      }

      // Tenta primeiro com zoom alto para precisão
      const nominatimUrl = `/radci/api/reverse.php?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=1`;
      const response = await fetch(nominatimUrl);
      if (!response.ok) {
        throw new Error(`Falha na requisição: ${response.status}`);
      }
      
      const data = await response.json();
      if (!data || !data.address) {
        throw new Error('Dados de endereço não encontrados');
      }

      const address = data.address;
      let streetName = '';
      let number = '';
      
      // Prioriza tipos de vias mais comuns
      if (address.road) streetName = address.road;
      else if (address.highway) streetName = address.highway;
      else if (address.pedestrian) streetName = address.pedestrian;
      else if (address.footway) streetName = address.footway;
      else if (address.street) streetName = address.street;
      else if (address.path) streetName = address.path;
      
      // Adiciona o número se disponível
      if (address.house_number) {
        number = address.house_number;
      }

      // Se não encontrou a rua, tenta com zoom menor
      if (!streetName) {
        const nominatimUrlLowerZoom = `/radci/api/reverse.php?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=16&addressdetails=1`;
        const responseLowerZoom = await fetch(nominatimUrlLowerZoom);
        if (responseLowerZoom.ok) {
          const dataLowerZoom = await responseLowerZoom.json();
          if (dataLowerZoom?.address) {
            const addressLowerZoom = dataLowerZoom.address;
            if (addressLowerZoom.road) streetName = addressLowerZoom.road;
            else if (addressLowerZoom.highway) streetName = addressLowerZoom.highway;
            else if (addressLowerZoom.pedestrian) streetName = addressLowerZoom.pedestrian;
            else if (addressLowerZoom.footway) streetName = addressLowerZoom.footway;
            else if (addressLowerZoom.street) streetName = addressLowerZoom.street;
            else if (addressLowerZoom.path) streetName = addressLowerZoom.path;
          }
        }
      }

      // Se encontrou um nome de rua válido
      if (streetName) {
        const postcode = String(address.postcode || '').replace(/\D/g, '');
        
        // Se tiver CEP, tenta complementar com dados do ViaCEP
        if (postcode?.length === 8) {
          try {
            const viacepUrl = `/radci/api/viacep.php?cep=${postcode}`;
            const viacepRes = await fetch(viacepUrl);
            if (viacepRes.ok) {
              const viacepData = await viacepRes.json();
              
              if (viacepData && !viacepData.erro) {
                // Usa o nome da rua do ViaCEP se for mais completo
                if (viacepData.logradouro && viacepData.logradouro.length > streetName.length) {
                  streetName = viacepData.logradouro;
                }
                
                // Monta o endereço completo
                const fullAddress = [
                  streetName + (number ? `, ${number}` : ''),
                  viacepData.bairro || address.suburb || address.neighbourhood || '',
                  viacepData.localidade || address.city || address.town || address.municipality || '',
                  viacepData.uf || (address.state ? address.state.match(/[A-Z]{2}/)?.[0] || address.state : '')
                ].filter(part => part && part.trim().length > 1).join(', ');

                // Atualiza os campos
                if (fullAddress.includes(',')) {
                  addressEl.value = fullAddress;
                  
                  const cepField = document.getElementById('cep');
                  if (cepField) {
                    cepField.value = viacepData.cep || postcode.replace(/(\d{5})(\d{3})/, '$1-$2');
                  }
                  
                  return;
                }
              }
            }
          } catch (viacepErr) {
            // Continua com os dados do Nominatim se o ViaCEP falhar
          }
        }
        
        // Se não conseguiu usar o ViaCEP, monta o endereço com dados do Nominatim
        const fullAddress = [
          streetName + (number ? `, ${number}` : ''),
          address.suburb || address.neighbourhood || address.quarter || address.district || '',
          address.city || address.town || address.village || address.municipality || '',
          address.state_code || (address.state ? address.state.match(/[A-Z]{2}/)?.[0] || address.state : '')
        ].filter(part => part && part.trim().length > 1).join(', ');

        // Só atualiza se encontrou um endereço válido com vírgulas
        if (fullAddress.includes(',')) {
          addressEl.value = fullAddress;
          
          if (postcode?.length === 8) {
            const cepField = document.getElementById('cep');
            if (cepField) {
              cepField.value = postcode.replace(/(\d{5})(\d{3})/, '$1-$2');
            }
          }
        }
      }
    } catch (error) {
      // Mantém as coordenadas mesmo em caso de erro
      coordsEl.value = `${lat},${lng}`;
      
      // Não mostra erro se já tiver um endereço
      if (!addressEl.value.trim()) {
        addressEl.value = '';
      }
    }
  }
  if (!mapEl || typeof L === 'undefined') {
    console.error('Leaflet não carregado ou mapa ausente');
    return;
  }

  // Estado e helpers
  // NÃO redeclarar map/marker/DEFAULT/lastRequestId/currentAbort/refineWatchId aqui

  function stopRefine() {
    if (refineWatchId !== null) {
      try { navigator.geolocation.clearWatch(refineWatchId); } catch(_) {}
      refineWatchId = null;
    }
  }

  const accBadge = document.createElement('div');
  accBadge.id = 'gpsBadge';
  accBadge.className = 'absolute top-2 left-2 z-10 px-2 py-1 text-xs bg-black/60 text-white rounded';
  accBadge.style.display = 'none';
  mapEl.appendChild(accBadge);

  function setCoords(lat, lng) { coordsEl.value = `${lat},${lng}`; }
  function formatCepDigits(s) { const d=(s||'').replace(/\D/g,''); return d.length===8?`${d.slice(0,5)}-${d.slice(5)}`:s; }
  function resumir(display_name) { if(!display_name) return ''; const parts=display_name.split(',').map(p=>p.trim()).filter(Boolean); return parts.slice(0,4).join(', '); }

  // Cria o mapa e configura os eventos
  function createMap(start) {
    map = L.map(mapEl, {
      attributionControl: false
    }).setView(start, 16);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19
    }).addTo(map);
    
    marker = L.marker(start, { draggable: true }).addTo(map);
    setCoords(start[0], start[1]);
    map.invalidateSize();

    // Botão "minha localização"
    const LocateControl = L.control({position: 'topright'});
    LocateControl.onAdd = function() {
      const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
      const btn = L.DomUtil.create('a', '', container);
      btn.href = '#';
      btn.title = 'Usar minha localização';
      btn.innerHTML = '📍';
      btn.style.padding = '6px';
      btn.style.fontSize = '16px';
      L.DomEvent.on(btn, 'click', L.DomEvent.stop).on(btn, 'click', async () => {
        stopRefine();
        try {
          const pos = await getCurrentPositionWithTimeout(10000);
          await applyPosition(pos.coords.latitude, pos.coords.longitude, {reverse:true});
        } catch (err) {
          alert('Não foi possível obter sua localização.');
        }
      });
      return container;
    };
    LocateControl.addTo(map);

    // Interação manual: para refino e faz reverse
    marker.on('dragend', async () => { 
      stopRefine(); 
      const p = marker.getLatLng(); 
      await applyPosition(p.lat, p.lng, {reverse:true}); 
    });
    
    map.on('click', async (e) => { 
      stopRefine(); 
      await applyPosition(e.latlng.lat, e.latlng.lng, {reverse:true}); 
    });
  }

  // Busca bounding box da cidade/UF para limitar resultados ao perímetro correto
  async function getCityBounds(city, uf) {
      if (!city || !uf) return null;
      const { id, signal } = newRequest();
      try {
          const res = await fetch(`../api/geocode.php?q=${encodeURIComponent(`${city}, ${uf} Brasil`)}&limit=1`, { signal });
          if (id !== lastRequestId) return null;
          const data = await res.json();
          if (Array.isArray(data) && data[0] && data[0].boundingbox) {
              const bb = data[0].boundingbox; // [south, north, west, east]
              const south = parseFloat(bb[0]);
              const north = parseFloat(bb[1]);
              const west  = parseFloat(bb[2]);
              const east  = parseFloat(bb[3]);
              return {
                  left: west,
                  top: north,
                  right: east,
                  bottom: south,
                  viewbox: `${west},${north},${east},${south}`,
                  centerLat: parseFloat(data[0].lat),
                  centerLon: parseFloat(data[0].lon)
              };
          }
      } catch (err) { if (err.name !== 'AbortError') console.error('getCityBounds', err); }
      return null;
  }

  // Escolhe melhor candidato com cidade/UF normalizados e filtro pelo perímetro
  function pickBest(results, opts = {}) {
    if (!Array.isArray(results) || results.length === 0) return null;

    const expectedPostal = String(opts.expectedPostal || '').replace(/\D/g,'');
    const expectedCityN = norm(opts.expectedCity || '');
    const expectedUF = toUF(opts.expectedState || '');
    const expectedStreetN = norm(opts.expectedStreet || '');
    const expectedNeighbourhoodN = norm(opts.expectedNeighbourhood || '');
    const expectedHouseNumberN = norm(opts.expectedHouseNumber || '');
    const bounds = opts.bounds || null;
    const strictPostal = !!opts.strictPostal;

    // 1) CEP exato dentro do perímetro
    if (expectedPostal) {
      const cepMatches = results.filter(r => {
        const rp = String(r.address?.postcode || '').replace(/\D/g,'');
        const lat = parseFloat(r.lat), lon = parseFloat(r.lon);
        return rp === expectedPostal && isWithinBounds(lat, lon, bounds);
      });
      if (cepMatches.length) {
        cepMatches.sort((a,b) => (parseFloat(b.importance || 0) - parseFloat(a.importance || 0)));
        return cepMatches[0];
      }
      if (strictPostal) return null;
    }

    // 2) Filtra por cidade e UF normalizados
    const cityUfMatches = results.filter(r => {
      const cityRaw = r.address?.city || r.address?.town || r.address?.village || r.address?.municipality || '';
      const ufRaw = r.address?.state_code || r.address?.state || '';
      const cityN = norm(cityRaw);
      const candUF = toUF(ufRaw);
      const lat = parseFloat(r.lat), lon = parseFloat(r.lon);

      const cityOk = expectedCityN ? cityN.includes(expectedCityN) : true;
      const ufOk = expectedUF ? candUF === expectedUF : true;

      return cityOk && ufOk && isWithinBounds(lat, lon, bounds);
    });

    const baseList = cityUfMatches.length ? cityUfMatches : results;

    // 3) Pontua rua, número e bairro
    const scored = baseList.map(r => {
      const road = r.address?.road || r.address?.pedestrian || r.address?.footway || r.address?.path || '';
      const num  = r.address?.house_number || '';
      const sub  = r.address?.suburb || r.address?.neighbourhood || r.address?.quarter || r.address?.hamlet || '';
      const roadN = norm(road);
      const numN  = norm(num);
      const subN  = norm(sub);

      let score = 0;
      if (expectedStreetN && roadN.includes(expectedStreetN)) score += 60;
      if (expectedHouseNumberN && numN && numN === expectedHouseNumberN) score += 40;
      if (expectedNeighbourhoodN && subN.includes(expectedNeighbourhoodN)) score += 20;

      score += parseFloat(r.importance || 0);
      return { r, score };
    });

    if (strictPostal) {
      const filtered = scored.filter(s => s.score >= 40); // exige pelo menos rua ou número
      if (!filtered.length) return null;
      filtered.sort((a,b) => b.score - a.score);
      return filtered[0].r;
    }

    scored.sort((a,b) => b.score - a.score);
    return (scored[0] || {}).r || null;
  }

  // Geocodificação via API local (texto livre), com pick refinado e manutenção da posição do mapa
  async function geocodeAddress(query, expectedPostal = null, expectedCity = '', expectedState = '', expectedStreet = '', expectedNeighbourhood = '') {
    const { id, signal } = newRequest();
    try {
      // Extrai o número do endereço e verifica se é par/ímpar
      const addressInfo = (() => {
        const match = query.match(/\b\d+\b/);
        const isValid = match !== null;
        const info = {
          number: isValid ? match[0] : null,
          isValid,
          isEven: isValid ? (parseInt(match[0]) % 2 === 0) : null,
          originalLat: marker ? marker.getLatLng().lat : null,
          originalLng: marker ? marker.getLatLng().lng : null
        };
        return info;
      })();
      
      // Primeiro tenta buscar pelo CEP se disponível
      const cepMatch = query.match(/\d{5}-?\d{3}/);
      if (cepMatch) {
        const cepDigits = cepMatch[0].replace(/\D/g, '');
        try {
          const viacepRes = await fetch(`/radci/api/viacep.php?cep=${cepDigits}`, { signal });
          const viacepData = await viacepRes.json();
          
          if (!viacepData.erro) {
            // Usa o endereço do ViaCEP para uma busca mais precisa
            const searchQuery = `${viacepData.logradouro}, ${viacepData.bairro}, ${viacepData.localidade}, ${viacepData.uf}`;
            const res = await fetch(`/radci/api/geocode.php?q=${encodeURIComponent(searchQuery)}&limit=10`, { signal });
            if (id !== lastRequestId) return null;
            const results = await res.json();
            
            if (Array.isArray(results) && results.length > 0) {
              // Prioriza resultados do mesmo lado da rua (par/ímpar)
              if (addressInfo.isEven !== null) {
                results.forEach(r => {
                  if (r.address && r.address.house_number) {
                    const resultNumber = parseInt(r.address.house_number);
                    const resultIsEven = resultNumber % 2 === 0;
                    if (resultIsEven === addressInfo.isEven) {
                      r.importance = (parseFloat(r.importance) || 0) + 2;
                    }
                  }
                });
              }

              // Adiciona peso extra para resultados que correspondem ao número exato
              if (addressInfo.isValid) {
                results.forEach(r => {
                  if (r.address && r.address.house_number === addressInfo.number) {
                    r.importance = (parseFloat(r.importance) || 0) + 1;
                  }
                });
              }

              const best = pickBest(results, {
                expectedPostal: cepDigits,
                expectedCity: viacepData.localidade,
                expectedState: viacepData.uf,
                expectedStreet: viacepData.logradouro,
                expectedNeighbourhood: viacepData.bairro,
                expectedHouseNumber: addressInfo.number || '',
                strictPostal: true
              });
              if (best) return best;
            }
          }
        } catch (e) {
          console.warn('Erro ao buscar CEP:', e);
        }
      }

      // Se não encontrou pelo CEP ou não tinha CEP, tenta busca normal
      const res = await fetch(`/radci/api/geocode.php?q=${encodeURIComponent(query)}&limit=10`, { signal });
      if (id !== lastRequestId) return null;
      const results = await res.json();
      if (!Array.isArray(results) || results.length === 0) return null;
      
      // Adiciona peso extra para resultados que correspondem ao número exato
      if (addressInfo.isValid) {
        results.forEach(r => {
          if (r.address && r.address.house_number === addressInfo.number) {
            r.importance = (parseFloat(r.importance) || 0) + 1;
          }
        });
      }
      
      const best = pickBest(results, { 
        expectedPostal, 
        expectedCity, 
        expectedState, 
        expectedStreet, 
        expectedNeighbourhood,
        expectedHouseNumber: addressInfo.number || ''
      });
      
      // Se não encontrou o melhor resultado, tenta uma busca mais ampla
      if (!best) {
        const broadQuery = query.split(',')[0].trim(); // Usa apenas a primeira parte do endereço
        const broadRes = await fetch(`/radci/api/geocode.php?q=${encodeURIComponent(broadQuery)}&limit=10`, { signal });
        if (id !== lastRequestId) return null;
        const broadResults = await broadRes.json();
        if (Array.isArray(broadResults) && broadResults.length > 0) {
          return pickBest(broadResults, {
            expectedStreet: broadQuery,
            expectedHouseNumber: addressInfo.number || ''
          });
        }
      }
      
      return best || results[0];
    } catch (err) {
      if (err.name !== 'AbortError') console.error('Erro na geocodificação:', err);
      return null;
    }
  }

  // CEP → usa bounding box da cidade e sempre move o mapa (com fallback)
  async function fromCepInput(cep) {
    stopRefine();
    const cepDigits = (cep || '').replace(/\D/g,'');
    if (cepDigits.length !== 8) return;
  
    try {
      const { id, signal } = newRequest();
  
      // ViaCEP → estrutura, com fallback se falhar (ex.: 502)
      const via = await fetch(`/radci/api/viacep.php?cep=${encodeURIComponent(cepDigits)}`, { signal });
      if (!via.ok) {
        const uPostal = `/radci/api/geocode.php?postalcode=${encodeURIComponent(cepDigits)}&countrycodes=br&limit=10`;
        const rPostal = await (await fetch(uPostal, { signal })).json();
        if (id !== lastRequestId) return;
        const bestPostal = pickBest(rPostal, { expectedPostal: cepDigits });
        if (bestPostal && bestPostal.lat && bestPostal.lon) {
          const lat = parseFloat(bestPostal.lat);
          const lng = parseFloat(bestPostal.lon);
          
          // Atualiza o mapa e o marcador
          map.setView([lat, lng], 17);
          marker.setLatLng([lat, lng]);
          coordsEl.value = `${lat},${lng}`;
          map.invalidateSize();
          
          cepEl.value = formatCepDigits(cepDigits);
          return;
        }
        console.error(`Erro ao consultar ViaCEP (HTTP ${via.status}).`);
        return;
      }
  
      const data = await via.json();
      if (id !== lastRequestId) return;
      if (data?.erro) { alert('CEP não encontrado'); return; }
  
      const rua = data.logradouro || '';
      const bairro = data.bairro || '';
      const cidade = data.localidade || '';
      const uf = data.uf || '';
  
      addressEl.value = `${rua}${bairro ? ', ' + bairro : ''} - ${cidade}, ${uf}`.trim();
      cepEl.value = formatCepDigits(cepDigits);
  
      // Limita pelo perímetro da cidade/UF
      const bounds = await getCityBounds(cidade, uf);
      const vbParam = bounds?.viewbox ? `&viewbox=${encodeURIComponent(bounds.viewbox)}&bounded=1` : '';
  
      // 1) street+postalcode+city+state com perímetro + BR
      const u1 = `/radci/api/geocode.php?street=${encodeURIComponent(rua)}&postalcode=${encodeURIComponent(cepDigits)}&city=${encodeURIComponent(cidade)}&state=${encodeURIComponent(uf)}&countrycodes=br${vbParam}&limit=10`;
      const r1 = await (await fetch(u1, { signal })).json();
      if (id !== lastRequestId) return;
      let best = pickBest(r1, {
        expectedPostal: cepDigits,
        expectedCity: cidade,
        expectedState: uf,
        expectedStreet: rua,
        expectedNeighbourhood: bairro,
        expectedNeighbourhood: bairro,
        strictPostal: true,
        bounds
      });
  
      // 2) postalcode puro com perímetro + BR
      if (!best) {
        const u2 = `/radci/api/geocode.php?postalcode=${encodeURIComponent(cepDigits)}&countrycodes=br${vbParam}&limit=10`;
        const r2 = await (await fetch(u2, { signal })).json();
        if (id !== lastRequestId) return;
        best = pickBest(r2, {
          expectedPostal: cepDigits,
          expectedCity: cidade,
          expectedState: uf,
          strictPostal: true,
          bounds
        }) || null;
      }
  
      if (best && best.lat && best.lon) {
        await applyPosition(parseFloat(best.lat), parseFloat(best.lon), { reverse: true });
      } else if (bounds && Number.isFinite(bounds.centerLat) && Number.isFinite(bounds.centerLon)) {
        await applyPosition(bounds.centerLat, bounds.centerLon, { reverse: true });
      } else {
        alert('Não foi possível localizar coordenadas para este CEP.');
      }
    } catch (err) {
      console.error('fromCepInput', err);
    }
  }

  // Endereço → busca estruturada limitada por cidade/UF; fallback centro da cidade
  async function fromAddressInput(q) {
    stopRefine();
    if (!q || q.trim().length < 4) return;
    try {
      console.log('Buscando endereço:', q);
      
      // Tenta primeiro uma busca direta do endereço completo
      const directSearch = await fetch(`/radci/api/geocode.php?q=${encodeURIComponent(q)}&limit=1`);
      const directResult = await directSearch.json();
      
      if (directResult && directResult[0] && directResult[0].lat && directResult[0].lon) {
        const lat = parseFloat(directResult[0].lat);
        const lng = parseFloat(directResult[0].lon);
        
        console.log('Encontrado diretamente:', lat, lng);
        
        // Atualiza o mapa e o marcador
        map.setView([lat, lng], 17);
        marker.setLatLng([lat, lng]);
        coordsEl.value = `${lat},${lng}`;
        map.invalidateSize();
        
        // Busca informações detalhadas do endereço
        const reverseData = await fetch(`/radci/api/reverse.php?lat=${lat}&lon=${lng}`);
        const addressInfo = await reverseData.json();
        
        if (addressInfo && addressInfo.address) {
          if (addressInfo.address.postcode) {
            cepEl.value = formatCepDigits(addressInfo.address.postcode);
          }
        }
        
        return;
      }
      
      // Se não encontrou diretamente, tenta parse estruturado
      const partsDash = q.split(' - ');
      const left = (partsDash[0] || '').trim();
      const right = (partsDash[1] || '').trim();

      // Rua e número
      const leftParts = left.split(',').map(s => s.trim()).filter(Boolean);
      const rua = leftParts[0] || '';
      const numero = leftParts.length >= 2 ? leftParts[1] : '';

      // Bairro, Cidade, UF
      const rightParts = right.split(',').map(s => s.trim()).filter(Boolean);
      let uf = '', cidade = '', bairro = '';
      if (rightParts.length >= 1 && /^[A-Za-z]{2}$/.test(rightParts[rightParts.length - 1])) {
        uf = rightParts[rightParts.length - 1];
      }
      if (rightParts.length >= 2) cidade = rightParts[rightParts.length - 2];
      if (rightParts.length >= 3) bairro = rightParts.slice(0, rightParts.length - 2).join(', ');

      // Limita pela cidade/UF
      const bounds = await getCityBounds(cidade, uf);
      const vbParam = bounds?.viewbox ? `&viewbox=${encodeURIComponent(bounds.viewbox)}&bounded=1` : '';

      const streetQuery = numero ? `${rua} ${numero}` : rua;
      const { id, signal } = newRequest();

      // 1) estruturado com perímetro + country br
      const url1 = `/radci/api/geocode.php?street=${encodeURIComponent(streetQuery)}&city=${encodeURIComponent(cidade)}&state=${encodeURIComponent(uf)}&countrycodes=br${vbParam}&limit=10`;
      const r1 = await (await fetch(url1, { signal })).json();
      if (id !== lastRequestId) return;

      let found = pickBest(r1, {
        expectedCity: cidade,
        expectedState: uf,
        expectedStreet: rua,
        expectedNeighbourhood: bairro,
        expectedHouseNumber: numero,
        bounds
      });

      // 2) fallback livre no perímetro
      if (!found) {
        const free = `${streetQuery} - ${bairro ? bairro + ', ' : ''}${cidade}, ${uf} Brasil`;
        const url2 = `/radci/api/geocode.php?q=${encodeURIComponent(free)}&countrycodes=br${vbParam}&limit=10`;
        const r2 = await (await fetch(url2, { signal })).json();
        if (id !== lastRequestId) return;
        found = pickBest(r2, {
          expectedCity: cidade,
          expectedState: uf,
          expectedStreet: rua,
          expectedNeighbourhood: bairro,
          expectedHouseNumber: numero,
          bounds
        });
      }

      if (found && found.lat && found.lon) {
        const lat = parseFloat(found.lat);
        const lng = parseFloat(found.lon);
        
        // Atualiza o mapa e o marcador
      map.setView([lat, lng], 17);
      marker.setLatLng([lat, lng]);
      coordsEl.value = `${lat},${lng}`;
      map.invalidateSize();
      
      // Remove qualquer mensagem de erro anterior
      const errorDiv = document.querySelector('.error-message');
      if (errorDiv) {
        errorDiv.remove();
      }
      
      // Busca o CEP da localização
      const reverseUrl = `/radci/api/reverse.php?lat=${lat}&lon=${lng}`;
      const reverseResponse = await fetch(reverseUrl, { signal });
      const reverseData = await reverseResponse.json();
      
      if (reverseData && reverseData.address && reverseData.address.postcode) {
        cepEl.value = formatCepDigits(reverseData.address.postcode);
      }
      
      // Não mostra mensagem de erro se temos coordenadas válidas
      return true;
      } else if (bounds && Number.isFinite(bounds.centerLat) && Number.isFinite(bounds.centerLon)) {
        map.setView([bounds.centerLat, bounds.centerLon], 13);
        marker.setLatLng([bounds.centerLat, bounds.centerLon]);
        coordsEl.value = `${bounds.centerLat},${bounds.centerLon}`;
        map.invalidateSize();
      } else {
        alert('Endereço não encontrado no mapa.');
      }
    } catch (err) {
      console.error('fromAddressInput', err);
    }
  }

  // Eventos CEP/Endereço (param o refino e reposicionam)
  let cepTimer = null;
  cepEl.addEventListener('input', (e) => {
    const raw = e.target.value.replace(/\D/g,'');
    e.target.value = formatCepDigits(raw);
    
    // Se tiver 8 dígitos, inicia a busca após um pequeno delay
    if (raw.length === 8) {
      if (cepTimer) clearTimeout(cepTimer);
      cepTimer = setTimeout(async () => {
        await fromCepInput(e.target.value);
        lastCepFetchedDigits = raw;
      }, 500);
    }
  });

  cepEl.addEventListener('blur', async (e) => {
    const digits = (e.target.value || '').replace(/\D/g,'');
    if (digits.length === 8 && digits !== lastCepFetchedDigits) {
      await fromCepInput(e.target.value);
      lastCepFetchedDigits = digits;
    }
  });

  cepEl.addEventListener('keydown', async (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const digits = (cepEl.value || '').replace(/\D/g,'');
      if (digits.length === 8) {
        await fromCepInput(cepEl.value);
        lastCepFetchedDigits = digits;
      }
    }
  });

  let addrTimer = null;
  addressEl.addEventListener('input', (e) => {
    const q = e.target.value;
    if (q.trim().length >= 5) {
      if (addrTimer) clearTimeout(addrTimer);
      addrTimer = setTimeout(async () => {
        await fromAddressInput(q);
      }, 500);
    }
  });

  addressEl.addEventListener('blur', async (e) => {
    const q = e.target.value;
    if (q.trim().length >= 5) {
      await fromAddressInput(q);
    }
  });

  addressEl.addEventListener('keydown', async (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const q = e.target.value;
      if (q.trim().length >= 5) {
        await fromAddressInput(q);
      }
    }
  });

  // Inicialização do mapa e localização
  (async function init() {
    try {
      // Primeiro cria o mapa com uma posição temporária
      createMap(DEFAULT);
      
      console.log('Solicitando permissão de localização...');
      
      // Solicita permissão explicitamente
      if (navigator.permissions && navigator.permissions.query) {
        const permission = await navigator.permissions.query({ name: 'geolocation' });
        if (permission.state === 'denied') {
          throw new Error('Permissão de localização negada');
        }
      }
      
      console.log('Obtendo localização precisa...');
      
      // Configura para alta precisão
      const options = {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 0
      };
      
      // Tenta obter a localização atual
      const position = await new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(resolve, reject, options);
      });
      
      console.log('Localização obtida:', position);
      
      const lat = position.coords.latitude;
      const lng = position.coords.longitude;
      const accuracy = position.coords.accuracy;
      
      // Verifica se as coordenadas são válidas
      if (!isFinite(lat) || !isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
        throw new Error('Coordenadas inválidas');
      }
      
      console.log('Coordenadas válidas:', lat, lng);
      
      // Atualiza o mapa para a posição atual
      map.setView([lat, lng], 18, { animate: false });
      marker.setLatLng([lat, lng]);
      
      // Atualiza o campo de coordenadas
      coordsEl.value = `${lat},${lng}`;
      
      // Tenta obter os dados do endereço
      console.log('Obtendo dados do endereço...');
      await doReverse(lat, lng);
      
      // Atualiza o indicador de precisão
      const accBadge = document.getElementById('gpsBadge');
      if (accBadge) {
        if (accuracy <= 100) {
          accBadge.textContent = `Localização precisa (${Math.round(accuracy)}m)`;
          accBadge.style.backgroundColor = '#10B981'; // verde
        } else {
          accBadge.textContent = `Precisão: ${Math.round(accuracy)}m`;
          accBadge.style.backgroundColor = '#F59E0B'; // amarelo
        }
        accBadge.style.display = 'block';
        accBadge.style.color = 'white';
        accBadge.style.padding = '4px 8px';
        accBadge.style.borderRadius = '4px';
      }
      
    } catch (err) {
      console.warn('Erro na inicialização:', err);
      
      // Tenta usar coordenadas salvas
      const saved = (coordsEl.value || '').split(',').map(Number);
      const hasSaved = saved.length === 2 && !isNaN(saved[0]) && !isNaN(saved[1]);
      
      if (hasSaved) {
        console.log('Usando coordenadas salvas:', saved);
        map.setView(saved, 16);
        marker.setLatLng(saved);
        await doReverse(saved[0], saved[1]);
      } else {
        console.log('Usando coordenadas padrão:', DEFAULT);
        map.setView(DEFAULT, 16);
        marker.setLatLng(DEFAULT);
        await doReverse(DEFAULT[0], DEFAULT[1]);
      }
      
      // Mostra mensagem de erro
      const accBadge = document.getElementById('gpsBadge');
      if (accBadge) {
        accBadge.textContent = 'Não foi possível obter sua localização';
        accBadge.style.backgroundColor = '#EF4444'; // vermelho
        accBadge.style.color = 'white';
        accBadge.style.padding = '4px 8px';
        accBadge.style.borderRadius = '4px';
        accBadge.style.display = 'block';
      }
    }
    
    // Configura os eventos do mapa
    marker.on('dragend', async () => {
      const pos = marker.getLatLng();
      console.log('Marcador movido para:', pos);
      await doReverse(pos.lat, pos.lng);
    });

    map.on('click', async (e) => {
      console.log('Clique no mapa em:', e.latlng);
      marker.setLatLng(e.latlng);
      await doReverse(e.latlng.lat, e.latlng.lng);
    });
    
  })();

});
<?php endif; ?>
</script>




</head>


<body class="min-h-screen bg-gray-100">
  <header class="bg-white border-b border-gray-200">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
      <form method="POST" class="m-0">
        <input type="hidden" name="step" value="<?= $step ?>" />
        <button type="submit" name="navigate" value="back" class="inline-flex items-center text-green-600 font-medium hover:text-green-800">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
          </svg>
          Voltar
        </button>
      </form>
    </div>
  </header>

  <main class="container mx-auto max-w-screen-lg px-4 py-4 pb-24">
    <?php if($step === 1): ?>
      <div class="bg-white rounded-xl shadow p-6">
        <h1 class="text-2xl font-bold mb-4">Passo 1 — Localização</h1>
        <form method="POST" class="space-y-4">
          <input type="hidden" name="step" value="1" />
          <input type="hidden" id="coordinates" name="coordinates" value="<?= htmlspecialchars(is_array($data['coordinates'] ?? null) ? implode(',', $data['coordinates']) : '') ?>" />

        <div class="mb-4">
          <label for="type" class="block text-sm font-medium text-gray-700">Categoria da Ocorrência</label>
          <select id="type" name="type" class="mt-1 w-full rounded-md border-gray-300">
            <option value="">Selecione uma categoria</option>
            <option value="saude" <?= ($data['type'] ?? '') === 'saude' ? 'selected' : '' ?>>Saúde</option>
            <option value="inovacao" <?= ($data['type'] ?? '') === 'inovacao' ? 'selected' : '' ?>>Inovação</option>
            <option value="mobilidade" <?= ($data['type'] ?? '') === 'mobilidade' ? 'selected' : '' ?>>Mobilidade</option>
            <option value="politicas" <?= ($data['type'] ?? '') === 'politicas' ? 'selected' : '' ?>>Políticas Públicas</option>
            <option value="riscos" <?= ($data['type'] ?? '') === 'riscos' ? 'selected' : '' ?>>Riscos Urbanos</option>
            <option value="sustentabilidade" <?= ($data['type'] ?? '') === 'sustentabilidade' ? 'selected' : '' ?>>Sustentabilidade</option>
            <option value="planejamento" <?= ($data['type'] ?? '') === 'planejamento' ? 'selected' : '' ?>>Planejamento Urbano</option>
            <option value="educacao" <?= ($data['type'] ?? '') === 'educacao' ? 'selected' : '' ?>>Educação</option>
            <option value="meio" <?= ($data['type'] ?? '') === 'meio' ? 'selected' : '' ?>>Meio Ambiente</option>
            <option value="infraestrutura" <?= ($data['type'] ?? '') === 'infraestrutura' ? 'selected' : '' ?>>Infraestrutura</option>
            <option value="seguranca" <?= ($data['type'] ?? '') === 'seguranca' ? 'selected' : '' ?>>Segurança Pública</option>
            <option value="energias" <?= ($data['type'] ?? '') === 'energias' ? 'selected' : '' ?>>Energias Inteligentes</option>
          </select>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="md:col-span-2">
            <label for="address" class="block text-sm font-medium text-gray-700">Endereço</label>
            <input id="address" name="address" type="text" value="<?= htmlspecialchars($data['address'] ?? '') ?>" class="mt-1 w-full rounded-md border-gray-300" placeholder="Rua, Bairro - Cidade, UF" />
          </div>

          <div>
            <label for="number" class="block text-sm font-medium text-gray-700">Número</label>
            <input id="number" name="number" type="text" value="<?= htmlspecialchars($data['number'] ?? '') ?>" class="mt-1 w-full rounded-md border-gray-300" placeholder="123" />
          </div>
        </div>

        <div>
          <label for="cep" class="block text-sm font-medium text-gray-700">CEP</label>
          <input id="cep" name="cep" type="text" value="<?= htmlspecialchars($data['cep'] ?? '') ?>" class="mt-1 w-full rounded-md border-gray-300" placeholder="00000-000" />
        </div>


        <div id="map" class="relative w-full h-64 md:h-72 lg:h-80 rounded-lg border border-gray-200"></div>

        <div class="flex justify-end">
          <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700">Continuar</button>
        </div>
    <?php elseif($step === 2): ?>
      <div class="bg-white rounded-xl shadow p-6">
        <h1 class="text-2xl font-bold mb-4">Passo 2 — Detalhes</h1>
        <form method="POST" enctype="multipart/form-data" class="space-y-6">
          <input type="hidden" name="step" value="2" />
    
          <!-- Tipo de manifestação (radio visível com bolinha preenchida) -->
          <?php $selectedType = $data['type'] ?? ''; ?>
          <div>
            <p class="block text-sm font-medium text-gray-700 mb-2">Tipo de manifestação</p>
            <div class="space-y-3">
              <label class="flex items-center gap-3 px-3 py-2 border border-gray-300 rounded-lg cursor-pointer hover:border-green-400">
                <input type="radio" name="type" value="Sugestão de Melhoria"
                       class="accent-green-600 w-4 h-4" <?= $selectedType==='Sugestão de Melhoria'?'checked':'' ?> required>
                <span class="text-gray-900">Sugestão de Melhoria</span>
              </label>
    
              <label class="flex items-center gap-3 px-3 py-2 border border-gray-300 rounded-lg cursor-pointer hover:border-green-400">
                <input type="radio" name="type" value="Reclamação"
                       class="accent-green-600 w-4 h-4" <?= $selectedType==='Reclamação'?'checked':'' ?> required>
                <span class="text-gray-900">Reclamação</span>
              </label>
    
              <label class="flex items-center gap-3 px-3 py-2 border border-gray-300 rounded-lg cursor-pointer hover:border-green-400">
                <input type="radio" name="type" value="Elogio"
                       class="accent-green-600 w-4 h-4" <?= $selectedType==='Elogio'?'checked':'' ?> required>
                <span class="text-gray-900">Elogio</span>
              </label>
            </div>
          </div>
    
          <!-- Descrição -->
          <div>
            <label for="description" class="block text-sm font-medium text-gray-700">Descrição *</label>
            <textarea id="description" name="description" rows="4" class="mt-1 w-full rounded-md border-gray-300" placeholder="Descreva o problema ou sua sugestão em detalhes..." required><?= htmlspecialchars($data['description'] ?? '') ?></textarea>
          </div>
    
          <!-- Imagens ou Vídeos (dropzone bonito) -->
          <div>
            <p class="block text-sm font-medium text-gray-700 mb-2">Imagens ou Vídeos *</p>
            <div id="dropzone" class="w-full rounded-lg border-2 border-dashed border-gray-300 bg-white p-8 text-center transition">
              <div class="mx-auto mb-4 w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17a4 4 0 004 4h8a4 4 0 004-4"/>
                </svg>
              </div>
              <p id="fileInfo" class="text-gray-600">Arraste arquivos ou clique para selecionar</p>
              <p id="fileError" class="mt-2 text-sm text-red-600"></p>
              <button type="button" id="btnSelectFiles" class="mt-4 bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700">Selecionar Arquivos</button>
              <input id="fileInput" type="file" name="files[]" multiple accept="image/*,video/*" class="hidden" />
            </div>
            
            <!-- Área de pré-visualização -->
            <div id="previewArea" class="mt-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4"></div>
          </div>
    
          <div class="flex justify-between">
            <button type="submit" name="navigate" value="back" formnovalidate class="px-4 py-2 rounded-md border border-gray-300 text-gray-700">Voltar</button>
            <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700">Pré-visualizar</button>
          </div>
        </form>
      </div>
    <?php elseif($step === 3): ?>
      <div class="bg-white rounded-xl shadow p-6">
        <h1 class="text-2xl font-bold mb-4">Passo 3 — Revisão</h1>

      <div class="bg-white rounded-xl shadow p-4 space-y-2 mb-6">
        <div><strong>Endereço:</strong> <?= htmlspecialchars($data['address'] ?? '') ?></div>
        <div><strong>CEP:</strong> <?= htmlspecialchars($data['cep'] ?? '') ?></div>
        <div><strong>Tipo:</strong> <?= htmlspecialchars($data['type'] ?? '') ?></div>
        <div><strong>Descrição:</strong> <?= nl2br(htmlspecialchars($data['description'] ?? '')) ?></div>
        <div><strong>Coordenadas:</strong> <?= implode(', ', array_map('number_format', $data['coordinates'] ?? [0,0], [6,6])) ?></div>
      </div>

      <div class="bg-white rounded-xl shadow p-4 mb-6">
        <h2 class="text-lg font-semibold mb-2">Local no mapa</h2>
        <!-- AUMENTO DE ALTURA NO PREVIEW -->
        <div id="mapPreview" class="relative w-full h-64 md:h-72 lg:h-80 rounded-lg border border-gray-200"></div>
        <script>
          // Inicializar mapa de pré-visualização
          document.addEventListener('DOMContentLoaded', function() {
            const lat = <?= json_encode(floatval($data['lat'] ?? $data['coordinates'][0] ?? -23.5505)) ?>;
            const lng = <?= json_encode(floatval($data['lng'] ?? $data['coordinates'][1] ?? -46.6333)) ?>;
            
            console.log('Coordenadas do mapa:', lat, lng);
            console.log('Dados completos:', <?= json_encode($data) ?>);
            
            // Aguardar um pouco para garantir que o elemento existe
            setTimeout(() => {
              const mapElement = document.getElementById('mapPreview');
              if (!mapElement) {
                console.error('Elemento mapPreview não encontrado');
                return;
              }
              
              try {
                const mapPreview = L.map('mapPreview').setView([lat, lng], 16);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                  attribution: '© OpenStreetMap contributors'
                }).addTo(mapPreview);
                
                // Adicionar marcador na posição selecionada
                L.marker([lat, lng]).addTo(mapPreview);
                
                console.log('Mapa inicializado com sucesso');
              } catch (error) {
                console.error('Erro ao inicializar mapa:', error);
              }
            }, 100);
          });
        </script>
      </div>

      <?php $files = $data['preview_files'] ?? []; if(!empty($files)): ?>
      <div class="bg-white rounded-xl shadow p-4 mb-6">
        <h2 class="text-lg font-semibold mb-2">Mídia</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
          <?php foreach($files as $f): 
            $url = htmlspecialchars($f['url'] ?? '');
            $type = (strpos($f['type'] ?? '', 'video') !== false) ? 'video' : 'image';
            $name = htmlspecialchars($f['name'] ?? '');
            $mime = htmlspecialchars($f['type'] ?? '');
          ?>
            <div class="cursor-pointer relative aspect-square rounded-lg overflow-hidden bg-gray-100 hover:opacity-80 transition-opacity" 
                 onclick="openMedia('<?= $url ?>', '<?= $type ?>', '<?= $mime ?>', '<?= $name ?>')"
                 data-media-url="<?= $url ?>" 
                 data-media-type="<?= $type ?>" 
                 data-media-mime="<?= $mime ?>"
                 data-media-name="<?= $name ?>">
              <?php if($type === 'image'): ?>
                <img src="<?= $url ?>" 
                     class="w-full h-full object-cover" 
                     alt="<?= $name ?>">
              <?php else: ?>
                <div class="w-full h-full bg-gray-800">
                  <!-- Ícone de play para vídeos -->
                  <div class="absolute inset-0 flex items-center justify-center bg-black/30">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 text-white" viewBox="0 0 24 24" fill="currentColor">
                      <path d="M8 5v14l11-7z"/>
                    </svg>
                  </div>
                  <!-- Indicador de vídeo -->
                  <div class="absolute top-2 right-2 bg-black/50 text-white text-xs px-2 py-1 rounded">
                    Vídeo
                  </div>
                </div>
              <?php endif; ?>
              <!-- Nome do arquivo -->
              <div class="absolute bottom-0 left-0 right-0 bg-black/50 text-white text-xs p-2 truncate">
                <?= $name ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <form method="POST" class="flex justify-between">
        <input type="hidden" name="step" value="3" />
        <button type="submit" name="navigate" value="back" class="px-4 py-2 rounded-md border border-gray-300 text-gray-700">Voltar</button>
        <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700">Finalizar</button>
      </form>

      <!-- Modal de mídia -->
      <div id="mediaModal" class="fixed inset-0 bg-black/90 hidden z-[9999]">
        <div class="relative w-full h-full flex flex-col items-center justify-center p-4">
          <!-- Botão fechar -->
          <button id="mediaClose" class="absolute top-4 right-4 text-white hover:text-gray-300 z-50 p-2" aria-label="Fechar">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <!-- Nome do arquivo -->
          <div id="mediaName" class="absolute top-4 left-4 bg-black/50 text-white px-3 py-1 rounded z-50 text-sm font-medium"></div>

          <!-- Container de mídia -->
          <div class="relative w-full h-full flex items-center justify-center">
            <img id="modalImage" class="max-h-[90vh] max-w-[90vw] object-contain hidden" alt="Pré-visualização de imagem" />
            <video id="modalVideo" class="max-h-[90vh] max-w-[90vw] hidden" controls controlsList="nodownload" playsinline preload="auto">
              <source id="modalVideoSource" src="" type="">
              <p class="text-white">Seu navegador não suporta a reprodução de vídeos.</p>
            </video>
          </div>

          <!-- Navegação -->
          <div class="absolute inset-y-0 left-0 flex items-center">
            <button id="prevMedia" class="p-2 text-white hover:text-gray-300 focus:outline-none">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
            </button>
          </div>
          <div class="absolute inset-y-0 right-0 flex items-center">
            <button id="nextMedia" class="p-2 text-white hover:text-gray-300 focus:outline-none">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </main>
  

<script>
// JavaScript para o dropzone de arquivos (Passo 2)
document.addEventListener('DOMContentLoaded', function() {
  const dropzone = document.getElementById('dropzone');
  const fileInput = document.getElementById('fileInput');
  const btnSelectFiles = document.getElementById('btnSelectFiles');
  const fileInfo = document.getElementById('fileInfo');
  const fileError = document.getElementById('fileError');

  if (!dropzone || !fileInput || !btnSelectFiles) return;

  // Clique no botão para abrir seletor
  btnSelectFiles.addEventListener('click', () => {
    fileInput.click();
  });

  // Clique na área do dropzone
  dropzone.addEventListener('click', (e) => {
    if (e.target === btnSelectFiles) return; // Evita duplo clique
    fileInput.click();
  });

  // Drag and drop
  dropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropzone.classList.add('border-green-400', 'bg-green-50');
  });

  dropzone.addEventListener('dragleave', (e) => {
    e.preventDefault();
    dropzone.classList.remove('border-green-400', 'bg-green-50');
  });

  dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('border-green-400', 'bg-green-50');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
      fileInput.files = files;
      updateFileInfo(files);
    }
  });

  // Mudança no input de arquivo
  fileInput.addEventListener('change', (e) => {
    updateFileInfo(e.target.files);
  });

  function updateFileInfo(files) {
    fileError.textContent = '';
    const previewArea = document.getElementById('previewArea');
    previewArea.innerHTML = ''; // Limpa previews anteriores
    
    if (files.length === 0) {
      fileInfo.textContent = 'Arraste arquivos ou clique para selecionar';
      return;
    }

    // Validação de arquivos
    const allowedTypes = ['image/', 'video/'];
    const maxFileSize = 100 * 1024 * 1024; // 100MB por arquivo
    let validFiles = 0;
    let errors = [];

    for (let file of files) {
      const isValidType = allowedTypes.some(type => file.type.startsWith(type));
      if (!isValidType) {
        errors.push(`${file.name}: tipo não suportado`);
        continue;
      }

      if (file.size > maxFileSize) {
        errors.push(`${file.name}: arquivo muito grande (máximo 100MB)`);
        continue;
      }
      
      validFiles++;
      
      // Criar elemento de pré-visualização
      const previewContainer = document.createElement('div');
      previewContainer.className = 'relative aspect-square rounded-lg overflow-hidden bg-gray-100 cursor-pointer';
      previewContainer.setAttribute('data-media-url', URL.createObjectURL(file));
      previewContainer.setAttribute('data-media-type', 'image');
      previewContainer.setAttribute('data-media-mime', file.type);
      previewContainer.setAttribute('data-media-name', file.name);
      
      if (file.type.startsWith('image/')) {
        // Pré-visualização de imagem
        const img = document.createElement('img');
        img.className = 'w-full h-full object-cover hover:opacity-80 transition-opacity';
        img.alt = file.name;
        
        // Usar URL.createObjectURL para pré-visualização
        const url = URL.createObjectURL(file);
        img.src = url;
        
        // Limpar URL quando a imagem carregar
        img.onload = () => URL.revokeObjectURL(url);
        
        previewContainer.appendChild(img);
        
        // Adicionar evento de clique para visualizar imagem
        previewContainer.addEventListener('click', () => {
          const modal = document.createElement('div');
          modal.className = 'fixed inset-0 bg-black/90 z-50 flex items-center justify-center p-4';
          modal.innerHTML = `
            <div class="relative max-w-4xl w-full">
              <button class="absolute -top-10 right-0 text-white hover:text-gray-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <img src="${url}" class="max-h-[90vh] max-w-full object-contain mx-auto" alt="${file.name}">
            </div>
          `;
          document.body.appendChild(modal);
          
          // Fechar modal
          modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('button')) {
              modal.remove();
            }
          });
        });
      } else if (file.type.startsWith('video/')) {
        // Pré-visualização de vídeo
        const video = document.createElement('video');
        video.className = 'w-full h-full object-cover';
        video.muted = true;
        
        const url = URL.createObjectURL(file);
        video.src = url;
        
        // Limpar URL quando o vídeo carregar
        video.onloadedmetadata = () => {
          video.currentTime = 0;
        };
        
        // Ícone de vídeo sobreposto
        const videoIcon = document.createElement('div');
        videoIcon.className = 'absolute inset-0 flex items-center justify-center bg-black/30';
        videoIcon.innerHTML = `
          <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 text-white" viewBox="0 0 24 24" fill="currentColor">
            <path d="M8 5v14l11-7z"/>
          </svg>
        `;
        
        previewContainer.appendChild(video);
        previewContainer.appendChild(videoIcon);
        
        // Adicionar evento de clique para reproduzir vídeo
        previewContainer.addEventListener('click', () => {
          const modal = document.createElement('div');
          modal.className = 'fixed inset-0 bg-black/90 z-50 flex items-center justify-center p-4';
          modal.innerHTML = `
            <div class="relative max-w-4xl w-full">
              <button class="absolute -top-10 right-0 text-white hover:text-gray-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
              <video src="${url}" class="max-h-[90vh] max-w-full mx-auto" controls autoplay></video>
            </div>
          `;
          document.body.appendChild(modal);
          
          // Fechar modal
          modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('button')) {
              const video = modal.querySelector('video');
              video.pause();
              modal.remove();
            }
          });
        });
      }
      
      previewArea.appendChild(previewContainer);
    }

    if (errors.length > 0) {
      fileError.textContent = errors.join(', ');
    }

    if (validFiles > 0) {
      fileInfo.textContent = `${validFiles} arquivo(s) selecionado(s)`;
      dropzone.classList.add('border-green-400');
    } else {
      fileInfo.textContent = 'Nenhum arquivo válido selecionado';
      dropzone.classList.remove('border-green-400');
    }
  }

  // Modal de mídia (Passo 3)
  const mediaModal = document.getElementById('mediaModal');
  const modalImage = document.getElementById('modalImage');
  const modalVideo = document.getElementById('modalVideo');
  const modalVideoSource = document.getElementById('modalVideoSource');
  const mediaClose = document.getElementById('mediaClose');
  const prevMedia = document.getElementById('prevMedia');
  const nextMedia = document.getElementById('nextMedia');
  const mediaName = document.getElementById('mediaName');

  if (mediaModal) {
    let currentMediaIndex = 0;
    let mediaItems = [];

    // Clique em mídia para abrir modal
    document.querySelectorAll('[data-media-url]').forEach((item, index) => {
      item.addEventListener('click', () => {
        mediaItems = Array.from(document.querySelectorAll('[data-media-url]'));
        currentMediaIndex = index;
        showMedia(currentMediaIndex);
      });
    });

    function showMedia(index) {
      const item = mediaItems[index];
      const url = item.dataset.mediaUrl;
      const type = item.dataset.mediaType;
      const mime = item.dataset.mediaMime;
      const name = item.dataset.mediaName;

      console.log('Abrindo mídia:', { url, type, mime, name });

      // Atualiza nome do arquivo
      mediaName.textContent = name;

      // Reseta estado
      modalImage.classList.add('hidden');
      modalVideo.classList.add('hidden');
      modalVideo.pause();
      modalVideo.currentTime = 0;

      if (type === 'image') {
        modalImage.src = url;
        modalImage.classList.remove('hidden');
      } else {
        // Configura o vídeo
        modalVideoSource.src = url;
        modalVideoSource.type = mime;
        
        // Remove event listeners anteriores para evitar duplicação
        modalVideo.removeEventListener('loadedmetadata', onVideoLoad);
        modalVideo.removeEventListener('error', onVideoError);
        
        // Adiciona event listeners
        modalVideo.addEventListener('loadedmetadata', onVideoLoad);
        modalVideo.addEventListener('error', onVideoError);
        
        // Carrega e exibe o vídeo
        modalVideo.load();
        modalVideo.classList.remove('hidden');
        
        console.log('Configurando vídeo:', { src: url, type: mime });
      }

      // Atualiza visibilidade dos botões de navegação
      prevMedia.style.visibility = index > 0 ? 'visible' : 'hidden';
      nextMedia.style.visibility = index < mediaItems.length - 1 ? 'visible' : 'hidden';

      mediaModal.classList.remove('hidden');
      mediaModal.classList.add('flex');
    }

    function onVideoLoad() {
      console.log('Vídeo carregado com sucesso:', {
        duration: this.duration,
        readyState: this.readyState,
        networkState: this.networkState
      });
      this.play().catch(e => console.error('Erro ao iniciar reprodução:', e));
    }

    function onVideoError(e) {
      console.error('Erro ao carregar vídeo:', {
        error: this.error,
        networkState: this.networkState,
        event: e
      });
      alert('Erro ao carregar o vídeo. Por favor, tente novamente.');
    }

    // Navegação
    prevMedia.addEventListener('click', () => {
      if (currentMediaIndex > 0) {
        currentMediaIndex--;
        showMedia(currentMediaIndex);
      }
    });

    nextMedia.addEventListener('click', () => {
      if (currentMediaIndex < mediaItems.length - 1) {
        currentMediaIndex++;
        showMedia(currentMediaIndex);
      }
    });

    // Navegação por teclado
    document.addEventListener('keydown', (e) => {
      if (!mediaModal.classList.contains('hidden')) {
        if (e.key === 'ArrowLeft' && currentMediaIndex > 0) {
          currentMediaIndex--;
          showMedia(currentMediaIndex);
        } else if (e.key === 'ArrowRight' && currentMediaIndex < mediaItems.length - 1) {
          currentMediaIndex++;
          showMedia(currentMediaIndex);
        } else if (e.key === 'Escape') {
          closeModal();
        }
      }
    });

    function closeModal() {
      console.log('Fechando modal e limpando mídia');
      
      // Pausa e limpa o vídeo
      if (modalVideo) {
        modalVideo.pause();
        modalVideo.currentTime = 0;
        modalVideoSource.removeAttribute('src');
        modalVideo.load(); // Importante: limpa o buffer do vídeo
        modalVideo.classList.add('hidden');
      }
      
      // Limpa a imagem
      if (modalImage) {
        modalImage.src = '';
        modalImage.classList.add('hidden');
      }
      
      // Remove event listeners do vídeo
      modalVideo.removeEventListener('loadedmetadata', onVideoLoad);
      modalVideo.removeEventListener('error', onVideoError);
      
      // Esconde o modal
      mediaModal.classList.add('hidden');
      mediaModal.classList.remove('flex');
      
      console.log('Modal fechado e mídia limpa com sucesso');
    }

    // Fechar modal
    mediaClose.addEventListener('click', () => {
      closeModal();
    });

    mediaModal.addEventListener('click', (e) => {
      if (e.target === mediaModal) {
        closeModal();
      }
    });
  }
});
</script>

</body>
</html>
