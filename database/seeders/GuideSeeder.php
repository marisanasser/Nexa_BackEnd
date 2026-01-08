<?php

namespace Database\Seeders;

use App\Models\Common\Guide;
use App\Models\Common\Step;
use Illuminate\Database\Seeder;

class GuideSeeder extends Seeder
{
    public function run(): void
    {

        $brandGuide = Guide::create([
            'title' => 'Guia Nexa para Marcas',
            'audience' => 'Brand',
            'description' => 'Aprenda como criar e gerenciar campanhas eficazes na plataforma Nexa. Este guia abrange desde a configuração inicial até o monitoramento de resultados.',
            'created_by' => 1,
        ]);

        $brandSteps = [
            [
                'title' => 'Definir Objetivo da Campanha',
                'description' => 'Escolha o que o sucesso significa para sua campanha: conscientização, conversões, UGC, instalações de app. Isso orienta todas as configurações, segmentação e medição na Nexa.',
                'order' => 0,
            ],
            [
                'title' => 'Público e Compatibilidade com Criadores',
                'description' => 'Selecione regiões-alvo, interesses e verticais de criadores. A Nexa recomenda os melhores criadores com base no desempenho histórico e alinhamento de nicho.',
                'order' => 1,
            ],
            [
                'title' => 'Orçamento e Incentivos',
                'description' => 'Defina o orçamento total, modelo de pagamento (fixo, baseado em performance) e níveis de recompensa. Isso impacta a motivação dos criadores e o ROI esperado.',
                'order' => 2,
            ],
            [
                'title' => 'Brief e Entregáveis',
                'description' => 'Escreva um brief criativo claro, faça e não faça da marca, formatos de conteúdo e prazos. A Nexa garante clareza para que os criadores entreguem conteúdo alinhado com a marca.',
                'order' => 3,
            ],
            [
                'title' => 'Rastreamento e Lançamento',
                'description' => 'Adicione UTMs, códigos promocionais e atribuição de plataforma. Revise o resumo e publique. A Nexa monitora o desempenho em tempo real pós-lançamento.',
                'order' => 4,
            ],
        ];

        foreach ($brandSteps as $stepData) {
            Step::create([
                'guide_id' => $brandGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }

        $creatorGuide = Guide::create([
            'title' => 'Guia Nexa para Criadores',
            'audience' => 'Creator',
            'description' => 'Descubra como maximizar seu sucesso na plataforma Nexa. Este guia abrange desde a otimização do perfil até a entrega de conteúdo de alta qualidade.',
            'created_by' => 1,
        ]);

        $creatorSteps = [
            [
                'title' => 'Perfil e Alinhamento de Nicho',
                'description' => 'Certifique-se de que seu perfil Nexa destaque seu nicho, estatísticas de audiência e trabalhos anteriores com marcas para que você combine com as campanhas certas.',
                'order' => 0,
            ],
            [
                'title' => 'Revisar Brief da Campanha',
                'description' => 'Entenda os objetivos da marca, tom e entregáveis. Faça perguntas esclarecedoras desde o início para evitar revisões posteriores.',
                'order' => 1,
            ],
            [
                'title' => 'Plano de Conteúdo e Cronograma',
                'description' => 'Rascunhe ganchos, conceitos e cronograma de postagem. Obtenha alinhamento da marca nas mensagens-chave antes da produção.',
                'order' => 2,
            ],
            [
                'title' => 'Criar e Enviar',
                'description' => 'Produza conteúdo seguindo as especificações. Envie rascunhos na Nexa para revisão e mantenha todos os threads de feedback em um lugar.',
                'order' => 3,
            ],
            [
                'title' => 'Publicar e Relatar',
                'description' => 'Publique nos canais acordados, depois adicione links, métricas e aprendizados. A Nexa agrega resultados para performance transparente.',
                'order' => 4,
            ],
        ];

        foreach ($creatorSteps as $stepData) {
            Step::create([
                'guide_id' => $creatorGuide->id,
                'title' => $stepData['title'],
                'description' => $stepData['description'],
                'order' => $stepData['order'],
            ]);
        }
    }
}
