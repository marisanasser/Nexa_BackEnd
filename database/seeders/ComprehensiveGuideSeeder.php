<?php

namespace Database\Seeders;

use App\Models\Guide;
use App\Models\Step;
use Illuminate\Database\Seeder;

class ComprehensiveGuideSeeder extends Seeder
{
    public function run(): void
    {

        Step::truncate();
        Guide::truncate();

        $brandRegistrationGuide = Guide::create([
            'title' => 'Registro de Marca na Nexa',
            'audience' => 'Brand',
            'description' => 'Guia completo para registrar sua marca na plataforma Nexa e configurar seu perfil para máxima visibilidade e credibilidade.',
            'created_by' => 1,
        ]);

        $brandRegistrationSteps = [
            [
                'title' => 'Acessar a Página de Registro',
                'description' => 'Visite nexa.com.br e clique em "Registrar como Marca". Você será direcionado para o formulário de registro específico para marcas.',
                'order' => 0,
            ],
            [
                'title' => 'Informações Básicas da Empresa',
                'description' => 'Preencha o nome da empresa, CNPJ, endereço e informações de contato. Certifique-se de que todas as informações estejam corretas para verificação.',
                'order' => 1,
            ],
            [
                'title' => 'Configurar Perfil da Marca',
                'description' => 'Adicione logo da marca, descrição da empresa, setor de atuação e valores da marca. Isso ajuda criadores a entender se há alinhamento.',
                'order' => 2,
            ],
            [
                'title' => 'Verificação de Documentos',
                'description' => 'Faça upload dos documentos necessários (CNPJ, comprovante de endereço). A verificação pode levar até 24 horas.',
                'order' => 3,
            ],
            [
                'title' => 'Configurar Métodos de Pagamento',
                'description' => 'Adicione cartões de crédito ou contas bancárias para pagamento de campanhas. Configure limites de gastos se necessário.',
                'order' => 4,
            ],
        ];

        foreach ($brandRegistrationSteps as $stepData) {
            Step::create([
                'guide_id' => $brandRegistrationGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }

        $createCampaignGuide = Guide::create([
            'title' => 'Como Criar uma Campanha Eficaz',
            'audience' => 'Brand',
            'description' => 'Aprenda a criar campanhas que atraem os melhores criadores e geram resultados excepcionais para sua marca.',
            'created_by' => 1,
        ]);

        $createCampaignSteps = [
            [
                'title' => 'Definir Objetivos e KPIs',
                'description' => 'Estabeleça metas claras: alcance, engajamento, conversões ou awareness. Defina métricas específicas para medir o sucesso da campanha.',
                'order' => 0,
            ],
            [
                'title' => 'Configurar Detalhes da Campanha',
                'description' => 'Adicione título atrativo, descrição detalhada, categoria, público-alvo e duração. Use palavras-chave que criadores buscariam.',
                'order' => 1,
            ],
            [
                'title' => 'Definir Orçamento e Remuneração',
                'description' => 'Estabeleça o orçamento total, valor por criador e modelo de pagamento (fixo, por performance, híbrido). Considere bônus por performance.',
                'order' => 2,
            ],
            [
                'title' => 'Criar Brief Criativo',
                'description' => 'Escreva um brief detalhado com diretrizes da marca, tom de voz, mensagens-chave, call-to-actions e exemplos de conteúdo desejado.',
                'order' => 3,
            ],
            [
                'title' => 'Configurar Filtros de Criadores',
                'description' => 'Defina critérios como número de seguidores, taxa de engajamento, localização, nicho e histórico de campanhas para encontrar criadores ideais.',
                'order' => 4,
            ],
            [
                'title' => 'Revisar e Publicar',
                'description' => 'Revise todos os detalhes, termos e condições. Publique a campanha para que criadores possam se candidatar.',
                'order' => 5,
            ],
        ];

        foreach ($createCampaignSteps as $stepData) {
            Step::create([
                'guide_id' => $createCampaignGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }

        $approveCreatorsGuide = Guide::create([
            'title' => 'Como Aprovar e Gerenciar Criadores',
            'audience' => 'Brand',
            'description' => 'Processo completo para avaliar candidaturas de criadores, aprovar os melhores talentos e gerenciar relacionamentos durante a campanha.',
            'created_by' => 1,
        ]);

        $approveCreatorsSteps = [
            [
                'title' => 'Analisar Candidaturas',
                'description' => 'Revise os perfis dos criadores candidatos, verifique métricas de audiência, taxa de engajamento e alinhamento com sua marca.',
                'order' => 0,
            ],
            [
                'title' => 'Avaliar Portfólio e Histórico',
                'description' => 'Examine trabalhos anteriores, qualidade do conteúdo, estilo visual e colaborações passadas com outras marcas.',
                'order' => 1,
            ],
            [
                'title' => 'Verificar Autenticidade da Audiência',
                'description' => 'Use as ferramentas da Nexa para verificar a qualidade da audiência, detectar seguidores falsos e validar engajamento genuíno.',
                'order' => 2,
            ],
            [
                'title' => 'Aprovar Criadores Selecionados',
                'description' => 'Aprove os criadores que melhor se alinham com sua campanha. Envie mensagens personalizadas explicando próximos passos.',
                'order' => 3,
            ],
            [
                'title' => 'Estabelecer Comunicação',
                'description' => 'Inicie o chat com criadores aprovados, esclareça dúvidas sobre o brief e estabeleça cronograma de entregáveis.',
                'order' => 4,
            ],
            [
                'title' => 'Monitorar Progresso',
                'description' => 'Acompanhe o progresso dos criadores, forneça feedback construtivo e aprove entregáveis conforme necessário.',
                'order' => 5,
            ],
        ];

        foreach ($approveCreatorsSteps as $stepData) {
            Step::create([
                'guide_id' => $approveCreatorsGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }

        $brandChatGuide = Guide::create([
            'title' => 'Comunicação Efetiva com Criadores',
            'audience' => 'Brand',
            'description' => 'Melhores práticas para se comunicar com criadores, dar feedback construtivo e manter relacionamentos produtivos.',
            'created_by' => 1,
        ]);

        $brandChatSteps = [
            [
                'title' => 'Iniciar Conversas Profissionalmente',
                'description' => 'Apresente-se, reforce os objetivos da campanha e estabeleça expectativas claras sobre comunicação e prazos.',
                'order' => 0,
            ],
            [
                'title' => 'Fornecer Feedback Construtivo',
                'description' => 'Seja específico sobre o que funciona e o que precisa ser ajustado. Use exemplos visuais e mantenha tom colaborativo.',
                'order' => 1,
            ],
            [
                'title' => 'Gerenciar Revisões de Conteúdo',
                'description' => 'Use o sistema de aprovação da Nexa para solicitar mudanças, aprovar conteúdo e manter histórico de todas as interações.',
                'order' => 2,
            ],
            [
                'title' => 'Resolver Conflitos e Problemas',
                'description' => 'Aborde questões rapidamente, seja transparente sobre limitações e use a mediação da Nexa quando necessário.',
                'order' => 3,
            ],
            [
                'title' => 'Manter Relacionamentos de Longo Prazo',
                'description' => 'Reconheça bom trabalho, forneça referências e considere criadores para futuras campanhas.',
                'order' => 4,
            ],
        ];

        foreach ($brandChatSteps as $stepData) {
            Step::create([
                'guide_id' => $brandChatGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }

        $creatorRegistrationGuide = Guide::create([
            'title' => 'Registro de Criador na Nexa',
            'audience' => 'Creator',
            'description' => 'Guia passo a passo para criar seu perfil de criador na Nexa e otimizá-lo para atrair as melhores oportunidades de marca.',
            'created_by' => 1,
        ]);

        $creatorRegistrationSteps = [
            [
                'title' => 'Criar Conta de Criador',
                'description' => 'Acesse nexa.com.br e selecione "Registrar como Criador". Use um email profissional que você monitora regularmente.',
                'order' => 0,
            ],
            [
                'title' => 'Conectar Redes Sociais',
                'description' => 'Conecte suas principais redes sociais (Instagram, TikTok, YouTube). A Nexa verificará automaticamente suas métricas de audiência.',
                'order' => 1,
            ],
            [
                'title' => 'Completar Perfil Profissional',
                'description' => 'Adicione bio profissional, nichos de atuação, demografia da audiência e experiência com marcas. Seja específico e honesto.',
                'order' => 2,
            ],
            [
                'title' => 'Criar Portfólio',
                'description' => 'Faça upload dos seus melhores trabalhos, incluindo campanhas anteriores, conteúdo orgânico e resultados alcançados.',
                'order' => 3,
            ],
            [
                'title' => 'Configurar Informações Bancárias',
                'description' => 'Adicione dados bancários para recebimento de pagamentos. Verifique se todas as informações estão corretas.',
                'order' => 4,
            ],
            [
                'title' => 'Verificação e Aprovação',
                'description' => 'Aguarde a verificação do perfil pela equipe Nexa. Mantenha todas as informações atualizadas durante este processo.',
                'order' => 5,
            ],
        ];

        foreach ($creatorRegistrationSteps as $stepData) {
            Step::create([
                'guide_id' => $creatorRegistrationGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }

        $creatorCampaignGuide = Guide::create([
            'title' => 'Como Se Candidatar a Campanhas',
            'audience' => 'Creator',
            'description' => 'Estratégias para encontrar campanhas ideais, criar propostas vencedoras e aumentar suas chances de aprovação.',
            'created_by' => 1,
        ]);

        $creatorCampaignSteps = [
            [
                'title' => 'Buscar Campanhas Relevantes',
                'description' => 'Use filtros da Nexa para encontrar campanhas que se alinham com seu nicho, audiência e valores. Foque em qualidade, não quantidade.',
                'order' => 0,
            ],
            [
                'title' => 'Analisar Brief da Campanha',
                'description' => 'Leia cuidadosamente todos os requisitos, objetivos da marca e entregáveis esperados. Certifique-se de que pode cumprir tudo.',
                'order' => 1,
            ],
            [
                'title' => 'Criar Proposta Personalizada',
                'description' => 'Escreva uma proposta que demonstre entendimento da marca, apresente ideias criativas e destaque sua experiência relevante.',
                'order' => 2,
            ],
            [
                'title' => 'Apresentar Métricas Relevantes',
                'description' => 'Inclua dados de audiência, taxa de engajamento, alcance médio e resultados de campanhas similares anteriores.',
                'order' => 3,
            ],
            [
                'title' => 'Definir Cronograma Realista',
                'description' => 'Proponha um cronograma factível que inclua tempo para criação, revisões e publicação. Seja realista com os prazos.',
                'order' => 4,
            ],
        ];

        foreach ($creatorCampaignSteps as $stepData) {
            Step::create([
                'guide_id' => $creatorCampaignGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }

        $creatorContentGuide = Guide::create([
            'title' => 'Criação e Entrega de Conteúdo',
            'audience' => 'Creator',
            'description' => 'Processo completo para criar conteúdo de alta qualidade que atende às expectativas das marcas e gera resultados.',
            'created_by' => 1,
        ]);

        $creatorContentSteps = [
            [
                'title' => 'Planejar Conceito Criativo',
                'description' => 'Desenvolva conceitos que alinhem sua personalidade com os objetivos da marca. Crie storyboards ou roteiros quando necessário.',
                'order' => 0,
            ],
            [
                'title' => 'Produzir Conteúdo de Qualidade',
                'description' => 'Use equipamentos adequados, cuide da iluminação e áudio. Mantenha consistência visual com sua marca pessoal.',
                'order' => 1,
            ],
            [
                'title' => 'Submeter para Aprovação',
                'description' => 'Envie rascunhos através da plataforma Nexa antes da publicação. Inclua contexto sobre decisões criativas tomadas.',
                'order' => 2,
            ],
            [
                'title' => 'Implementar Feedback',
                'description' => 'Responda ao feedback da marca de forma construtiva. Faça ajustes necessários mantendo sua autenticidade.',
                'order' => 3,
            ],
            [
                'title' => 'Publicar e Reportar',
                'description' => 'Publique no horário acordado, monitore performance e reporte métricas através da plataforma Nexa.',
                'order' => 4,
            ],
        ];

        foreach ($creatorContentSteps as $stepData) {
            Step::create([
                'guide_id' => $creatorContentGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }

        $creatorChatGuide = Guide::create([
            'title' => 'Comunicação Profissional com Marcas',
            'audience' => 'Creator',
            'description' => 'Como se comunicar efetivamente com marcas, negociar termos e manter relacionamentos profissionais duradouros.',
            'created_by' => 1,
        ]);

        $creatorChatSteps = [
            [
                'title' => 'Primeira Impressão Profissional',
                'description' => 'Responda rapidamente às mensagens das marcas, seja profissional e demonstre entusiasmo genuíno pelo projeto.',
                'order' => 0,
            ],
            [
                'title' => 'Fazer Perguntas Inteligentes',
                'description' => 'Esclareça dúvidas sobre objetivos, público-alvo, timing e expectativas. Isso demonstra profissionalismo e preparo.',
                'order' => 1,
            ],
            [
                'title' => 'Negociar Termos de Forma Respeitosa',
                'description' => 'Se necessário, negocie prazos, remuneração ou escopo de forma profissional. Sempre justifique suas solicitações.',
                'order' => 2,
            ],
            [
                'title' => 'Manter Comunicação Regular',
                'description' => 'Envie atualizações sobre o progresso, compartilhe rascunhos e mantenha a marca informada sobre o desenvolvimento.',
                'order' => 3,
            ],
            [
                'title' => 'Gerenciar Conflitos Profissionalmente',
                'description' => 'Se surgirem problemas, aborde-os diretamente mas de forma respeitosa. Use a mediação da Nexa quando necessário.',
                'order' => 4,
            ],
        ];

        foreach ($creatorChatSteps as $stepData) {
            Step::create([
                'guide_id' => $creatorChatGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }

        $platformGuide = Guide::create([
            'title' => 'Navegação na Plataforma Nexa',
            'audience' => 'General',
            'description' => 'Guia completo para navegar pela plataforma Nexa, usar todas as funcionalidades e maximizar sua experiência.',
            'created_by' => 1,
        ]);

        $platformSteps = [
            [
                'title' => 'Dashboard Principal',
                'description' => 'Entenda o layout do dashboard, notificações, menu principal e como acessar diferentes seções da plataforma.',
                'order' => 0,
            ],
            [
                'title' => 'Sistema de Notificações',
                'description' => 'Configure preferências de notificação, entenda diferentes tipos de alertas e como gerenciar comunicações.',
                'order' => 1,
            ],
            [
                'title' => 'Configurações de Perfil',
                'description' => 'Acesse e atualize informações pessoais, preferências de privacidade e configurações de conta.',
                'order' => 2,
            ],
            [
                'title' => 'Sistema de Pagamentos',
                'description' => 'Entenda como funcionam os pagamentos, visualize histórico financeiro e configure métodos de pagamento.',
                'order' => 3,
            ],
            [
                'title' => 'Suporte e Ajuda',
                'description' => 'Acesse central de ajuda, entre em contato com suporte e encontre recursos adicionais quando necessário.',
                'order' => 4,
            ],
        ];

        foreach ($platformSteps as $stepData) {
            Step::create([
                'guide_id' => $platformGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }
    }
}
