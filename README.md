<div align="center">

<img src="./public/coolify-logo.svg" alt="Nimbus Panel" width="120" />

# Nimbus Panel

**Coolify com Oracle Cloud (OCI) e Cloudflare integrados — deploy de apps, bancos e serviços nos seus servidores, com a infraestrutura criada por Terraform.**

Fork do [Coolify](https://github.com/coollabsio/coolify) mantido pela [Nimbus Network](https://nimbusnetwork.com.br). Licença Apache 2.0.

</div>

## O que é

O Nimbus Panel é o Coolify v4 (self-hosted, alternativa a Heroku/Netlify/Vercel) com uma camada para quem roda em **Oracle Cloud Infrastructure**:

- cria a VM na OCI pelo próprio painel (Terraform via OCI Resource Manager), já com Docker e chave SSH;
- gerencia firewall (NSG) como código, com versões e rollback;
- usa o OCI Monitoring como fonte de métricas;
- conecta a conta Cloudflare por OAuth para túnel SSH e publicação de domínios sem abrir portas.

Tudo que o Coolify faz continua igual: deploy por Git (Nixpacks, Railpack, Dockerfile, Compose, imagem), mais de 300 serviços one-click, bancos gerenciados, backups, previews de PR, API, CLI e MCP.

## Instalação

Em um servidor Linux limpo (Ubuntu/Debian/RHEL e derivados), como root:

```bash
curl -fsSL https://raw.githubusercontent.com/klaoslacerdacs/nimbus-panel/main/scripts/install.sh | bash
```

O instalador é o mesmo do Coolify (instala Docker, cria `/data/coolify`, gera chaves e sobe os containers), mas baixa os arquivos **deste repositório** e roda a imagem **`ghcr.io/klaoslacerdacs/nimbus-panel`**. Ao terminar, abra `http://<ip-do-servidor>:8000` e crie o usuário admin.

Variáveis opcionais (as do Coolify também valem, como `ROOT_USERNAME`, `ROOT_USER_EMAIL`, `ROOT_USER_PASSWORD`, `AUTOUPDATE`):

| Variável | Padrão | Uso |
| --- | --- | --- |
| `NIMBUS_REF` | `main` | Branch ou tag do repositório usada para baixar os arquivos |
| `NIMBUS_IMAGE` | `ghcr.io/klaoslacerdacs/nimbus-panel` | Imagem do painel (sem tag) |
| `REGISTRY_URL` | `docker.io` | Registry da imagem auxiliar `coollabsio/coolify-helper` |

Exemplo fixando uma versão:

```bash
curl -fsSL https://raw.githubusercontent.com/klaoslacerdacs/nimbus-panel/main/scripts/install.sh | NIMBUS_REF=v4.4.6 bash -s 4.4.6
```

Para atualizar uma instalação existente, rode o instalador de novo (ele detecta a instalação e só atualiza arquivos e imagem).

## Diferenças em relação ao Coolify

### Oracle Cloud (OCI)

| Recurso | Onde | Como funciona |
| --- | --- | --- |
| **Conexões OCI** | Security → OCI Connections | Chave de API (PEM criptografado no banco) ou **Instance Principal** quando o painel roda numa VM OCI (só para instance admin no team root). Cliente REST nativo em PHP com assinatura HTTP, retry e paginação — sem SDK Python. |
| **Criar servidor na OCI** | Servers → Add → Oracle Cloud | Wizard escolhe região, compartment, shape (inclusive Ampere A1), imagem, rede nova ou existente e volume. Cria um stack no **OCI Resource Manager** e roda *plan → apply*. Quando a VM sobe, o painel espera o SSH e valida o servidor sozinho. |
| **Terraform Jobs** | Security → Terraform Jobs | Histórico de cada plan/apply/destroy com status, duração, log do Terraform e link para o console OCI. |
| **Destroy com histórico** | Apagar servidor → "Also destroy OCI stack" | `terraform destroy` pelo Resource Manager; o stack fica como `destroyed` no histórico em vez de sumir. |
| **Firewall como código** | Servidor → aba OCI → Firewall | Regras do NSG são recursos Terraform. Toda mudança é *plan → apply* versionado; dá para restaurar qualquer versão. Um plan que destruiria/recriaria recursos aborta o apply. |
| **Firewall Policies** | Security → Firewall Policies | Conjuntos de regras compartilhados entre várias VMs (ex.: "web 80/443"). Também anexa NSGs criados direto no console OCI. Limite de 120 regras por NSG respeitado. |
| **VMs importadas** | Servidor → aba OCI | Para VMs que não foram criadas pelo wizard: edição direta do NSG, criar/anexar/desanexar vários NSGs. |
| **Métricas OCI Monitoring** | Servidor → Metrics | Com "OCI Monitoring" selecionado, CPU/memória/disco/rede vêm do OCI Monitoring e o Sentinel é desligado (sem alertas falsos). |
| **OCIR** | Server → Docker Registries | Provider "Oracle Cloud (OCIR)" preenche host e usuário `<namespace>/<user>` a partir da conexão. |
| **MCP** | Servidor MCP do painel | Tools `list_oci_connections`, `list_oci_stacks`, `get_oci_server`, `oci_instance_action` (start/stop/reboot com confirmação). |

Recursos OCI criados pelo painel recebem tags; nada é criado ou destruído na OCI sem *plan* antes.

### Cloudflare

| Recurso | Onde | Como funciona |
| --- | --- | --- |
| **Login com Cloudflare (OAuth)** | Settings → Cloudflare (admin da instância) e Security → Cloudflare (por time) | Authorization Code + PKCE com o OAuth client da sua conta. Tokens criptografados por time, renovados automaticamente quando o Cloudflare emite refresh token. Sem colar API tokens. |
| **SSH pelo túnel** | Servidor → Cloudflare Tunnel → "Fix SSH through the tunnel automatically" | Publica `ssh-<servidor>.<seu domínio>` → `ssh://localhost:22` no túnel, cria o CNAME, espera o DNS público e **só troca o acesso depois que o SSH pelo túnel funciona** (senão mantém o IP direto). |
| **Publicar domínios pelo túnel** | App/Service → Domains → "Publish through Cloudflare Tunnel" | Escolhe nome + domínio da conta; o domínio entra no app e é roteado no túnel para o Traefik (`https://localhost:443` com `noTLSVerify` + SNI, ou `http://localhost:80`). Inclui a porta interna exigida pelo template (ex.: Chaskiq `:3000`). Não precisa abrir 80/443. |

Scopes padrão do OAuth client: `account-settings.read zone.read dns.read dns.write argotunnel.write` (editáveis em Settings → Cloudflare).

### Atualizações

- O **auto-update do Coolify está desligado**: o painel não baixa versões do CDN da Coollabs nem mostra o botão *Upgrade*, para nunca trocar o Nimbus pela imagem oficial. (Reative só com `NIMBUS_ALLOW_UPSTREAM_UPDATE=true`, não recomendado.)
- Novidades do Coolify entram por **merge revisado** neste repositório (base atual: Coolify **v4.4.6**). A imagem é publicada pelo workflow [`nimbus-image.yml`](.github/workflows/nimbus-image.yml) em `ghcr.io/klaoslacerdacs/nimbus-panel`.

### Outras mudanças

- Aviso de "mudanças pendentes" com botão **Restart now** depois de salvar configurações de serviços (ex.: domínio).
- Cloudflare Tunnel: setup não trava mais para usuários SSH não-root e nunca deixa o painel sem acesso ao servidor ao ativar o túnel.

## Desenvolvimento

Mesma stack do Coolify: Laravel 12 (estrutura Laravel 10), Livewire 3, Tailwind v4, Pest 4, PHP 8.4+.

```bash
./scripts/dev start                 # sobe a instância de dev (http://localhost:8000)
./scripts/dev exec php artisan migrate
php artisan test --compact          # testes
vendor/bin/pint --dirty             # formatação
```

Guia completo em [`DEVELOPMENT.md`](DEVELOPMENT.md) e convenções para agentes em [`AGENTS.md`](AGENTS.md). O template Terraform das VMs fica em [`terraform/templates/environment`](terraform/templates/environment) (o `.zip` ao lado é o que vai para o Resource Manager e precisa ser regenerado quando os `.tf` mudam).

## Créditos e licença

Baseado no [Coolify](https://github.com/coollabsio/coolify) de [coollabs](https://coollabs.io) — todo o crédito da plataforma base é deles; considere [apoiar o projeto original](https://coolify.io/sponsorships). Distribuído sob a [Apache License 2.0](LICENSE). O Nimbus Panel não é afiliado nem endossado pela coollabs.
