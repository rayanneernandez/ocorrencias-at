# RADCI - Rede de Apoio ao Desenvolvimento de Cidades Inteligentes

Sistema web para registro e gerenciamento de ocorrências urbanas, facilitando a comunicação entre cidadãos e administração pública.

## 🚀 Funcionalidades

- **Dashboard Interativo**: Visualização de ocorrências por categoria
- **Registro de Ocorrências**: Sistema completo para reportar problemas urbanos
- **Geolocalização**: Integração com APIs de geocodificação
- **Múltiplos Perfis**: Cidadão, Admin RADCI, Admin Público e Secretário
- **Relatórios**: Sistema de relatórios para administradores
- **Pesquisas**: Criação e gerenciamento de pesquisas públicas

## 🛠️ Tecnologias

- **Backend**: PHP 8.1+
- **Banco de Dados**: MySQL
- **Frontend**: HTML5, CSS3, JavaScript, Tailwind CSS
- **APIs**: ViaCEP, Geocoding
- **Deploy**: Vercel

## 📋 Categorias de Ocorrências

- 🏥 Saúde
- 💡 Inovação
- 🚗 Mobilidade
- 📋 Políticas Públicas
- ⚠️ Riscos Urbanos
- 🌱 Sustentabilidade
- 🏗️ Planejamento Urbano
- 🎓 Educação
- 🌿 Meio Ambiente
- 🏢 Infraestrutura da Cidade
- 🛡️ Segurança Pública
- ⚡ Energias Inteligentes

## 🚀 Deploy no Vercel

Este projeto está configurado para deploy automático no Vercel:

1. Conecte seu repositório GitHub ao Vercel
2. O arquivo `vercel.json` já está configurado para PHP
3. Configure as variáveis de ambiente necessárias no painel do Vercel

### Variáveis de Ambiente (Vercel)

Configure no painel do Vercel:

```
DB_HOST=seu_host_mysql
DB_USER=seu_usuario
DB_PASS=sua_senha
DB_NAME=nome_do_banco
```

## 📁 Estrutura do Projeto

```
radci/
├── api/                    # APIs e endpoints
│   ├── geocode.php         # API de geocodificação
│   ├── reverse.php         # API de geocodificação reversa
│   └── viacep.php          # Integração com ViaCEP
├── assets/                 # Recursos estáticos
│   ├── css/                # Arquivos CSS
│   ├── js/                 # Arquivos JavaScript
│   └── images/             # Imagens do projeto
├── includes/               # Arquivos de inclusão
│   ├── db.php              # Configuração do banco de dados
│   └── mobile_nav.php      # Navegação mobile
├── pages/                  # Páginas da aplicação
│   ├── dashboard.php       # Dashboard principal
│   ├── login_cadastro.php  # Sistema de login e cadastro
│   ├── registrar_ocorrencia.php # Registro de ocorrências
│   ├── relatorios.php      # Sistema de relatórios
│   ├── usuarios.php        # Gerenciamento de usuários
│   ├── minha_conta.php     # Perfil do usuário
│   ├── minhas_ocorrencias.php # Ocorrências do usuário
│   ├── prioridades.php     # Definição de prioridades
│   ├── criar_pesquisa.php  # Criação de pesquisas
│   ├── pesquisas_respondidas.php # Pesquisas respondidas
│   ├── esqueceu_senha.php  # Recuperação de senha
│   ├── confirmar_reset_senha.php # Confirmação de reset
│   ├── solicitar_reset_senha.php # Solicitação de reset
│   └── salvar_preferencias.php # Salvar preferências
├── uploads/                # Diretório para uploads
│   ├── temp/               # Arquivos temporários
│   └── surveys/            # Arquivos de pesquisas
├── index.php               # Página inicial
├── db_inspect.php          # Inspeção do banco de dados
└── vercel.json            # Configuração do Vercel
```

## 🔧 Configuração Local

1. Clone o repositório
2. Configure um servidor local (XAMPP, WAMP, etc.)
3. Importe o banco de dados MySQL
4. Configure as credenciais em `db.php`
5. Acesse via `localhost`

## 👥 Perfis de Usuário

1. **Cidadão**: Registra ocorrências e visualiza dashboard
2. **Admin RADCI**: Gerencia usuários do sistema
3. **Admin Público**: Acessa relatórios e dados
4. **Secretário**: Acessa relatórios específicos

## 📱 Responsividade

O sistema é totalmente responsivo, funcionando em:
- Desktop
- Tablet
- Mobile

## 🤝 Contribuição

1. Fork o projeto
2. Crie uma branch para sua feature
3. Commit suas mudanças
4. Push para a branch
5. Abra um Pull Request

## 📄 Licença

Este projeto está sob licença MIT. Veja o arquivo LICENSE para mais detalhes.

---

**RADCI** - Conectando cidadãos e gestão pública para cidades mais inteligentes! 🏙️