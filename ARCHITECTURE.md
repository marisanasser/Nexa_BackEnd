# Nexa Backend Architecture

Este documento descreve a arquitetura atual do backend do Nexa, que evoluiu de um padrão MVC monolítico "messy" para uma estrutura orientada a domínio (Domain-Driven Design simplificado).

## 🏢 Visão Geral da Mudança

| Característica | Arquitetura Antiga (Messy MVC) | Arquitetura Nova (Simplified DDD) |
| :--- | :--- | :--- |
| **Organização** | Por tipo de arquivo (`Controllers`, `Models`, `Services`) | Por Domínio (`App/Domain/Payment`, `App/Models/Payment`) |
| **Coesão** | Baixa. Models misturados, Controllers gigantes. | Alta. Funcionalidades relacionadas estão juntas. |
| **Escalabilidade** | Difícil. Adicionar feature nova bagunçava pastas globais. | Fácil. Novo domínio = Nova pasta em `App/Domain/`. |
| **Models** | Todos na raiz de `App/Models`. Difícil encontrar. | Agrupados por contexto (`User/`, `Campaign/`, `Payment/`). |

---

## 📂 Estrutura de Pastas

A estrutura base da aplicação em `app/` agora segue esta organização:

### 1. `app/Domain/` (Lógica de Negócio)
Aqui reside a lógica pesada da aplicação, separada por contextos de negócio.

- **`Campaign/`**: Lógica de Campanhas, Aplicações, Reembolsos.
- **`Payment/`**: Integração com Stripe, Saques, Assinaturas, Saldo.
- **`Contract/`**: Gestão de Contratos, Ofertas, Entregas.
- **`Notification/`**: Sistema de notificações (Email, Database).
- **`User/`**: (Em construção) Lógica específica de usuários não coberta pelo Auth.

*Dentro de cada domínio, você encontrará:*
- `Services/`: Classes de serviço com regras de negócio.
- `Actions/`: Ações únicas e reutilizáveis (Command pattern simplificado).
- `Repositories/`: Acesso a dados (se necessário).
- `Providers/`: Service Providers específicos do domínio.

### 2. `app/Models/` (Entidades de Dados)
Os Models não ficam mais soltos na raiz.

- **`User/`**: `User`, `Review`, `Portfolio`, `Wallet`...
- **`Campaign/`**: `Campaign`, `Bid`, `CampaignApplication`...
- **`Payment/`**: `Transaction`, `Subscription`, `Withdrawal`, `JobPayment`...
- **`Contract/`**: `Contract`, `Offer`...
- **`Chat/`**: `Message`, `ChatRoom`...
- **`Common/`**: `Notification`, `Guide`...

### 3. `app/Http/Controllers/` (Camada HTTP)
Os controllers são apenas a porta de entrada. Eles também foram organizados para espelhar os domínios.

- **`Auth/`**: Login, Registro, Recuperação de Senha.
- **`Campaign/`**: Endpoints de campanha.
- **`Payment/`**: Webhooks do Stripe, Checkout.
- **`Contract/`**: Fluxo de contratação.
- **`Admin/`**: Painel administrativo.

---

## 🛠️ Guia de Desenvolvimento

### Como criar uma nova funcionalidade?

1.  **Identifique o Domínio**: A feature é sobre Pagamento? Usuário? Campanha? Se não existir, crie um novo em `app/Domain/NovoDominio`.
2.  **Crie o Model**: Coloque em `app/Models/{Dominio}/SeuModel.php`.
3.  **Crie a Lógica**:
    *   Se for complexa, crie um Service em `app/Domain/{Dominio}/Services/`.
    *   Se for uma ação simples, crie uma Action em `app/Domain/{Dominio}/Actions/`.
4.  **Crie o Controller**: Coloque em `app/Http/Controllers/{Dominio}/`.
    *   O controller deve ser magro. Injete o Service/Action e delegue a lógica.
5.  **Defina a Rota**: Em `routes/api.php`, use o namespace correto.

### Regras de Namespace

*   **Models**: `namespace App\Models\{Dominio};`
*   **Controllers**: `namespace App\Http\Controllers\{Dominio};`
*   **Services**: `namespace App\Domain\{Dominio}\Services;`

### Exemplo: Serviço de Pagamento

**Antigo (Ruim):**
Arquivo: `app/Services/PaymentService.php` (misturado com tudo)
Uso: `use App\Services\PaymentService;`

**Novo (Bom):**
Arquivo: `app/Domain/Payment/Services/PaymentService.php`
Uso: `use App\Domain\Payment\Services\PaymentService;`

---

## 🐛 Debugging e Logs

Se encontrar erros de `Class not found`, verifique:
1.  **Imports**: Certifique-se de que está importando o Model com o sub-namespace correto (ex: `use App\Models\User\User;` e não `use App\Models\User;`).
2.  **Dependências**: Services dentro de `App/Domain` são carregados automaticamente via autoload do Composer, mas certifique-se de que o namespace no topo do arquivo está correto.
