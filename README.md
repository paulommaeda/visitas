# Visitas — Acolher

Plugin WordPress para acolhimento e acompanhamento de visitantes de igrejas, com quadros Kanban, dashboard de gestão e identidade visual configurável. Versão atual: **1.1.0**.

## Instalação e primeiro acesso

1. Em **Plugins → Adicionar plugin → Enviar plugin**, envie `visitas-1.1.0.zip` e ative. Instalações com a versão 1.0.2 ou posterior podem atualizar pelo painel do WordPress. Para versões anteriores a 1.0.2, envie este ZIP uma vez e escolha substituir o plugin atual; quadros e configurações são preservados.
2. Clique em **Visitas → Abrir portal**. A ativação cria uma página chamada **Acolher**, normalmente em `/acolher/`. Em sites sem links permanentes, use o endereço mostrado pelo plugin.
3. Entre com seu usuário administrador WordPress.
4. Em **Personalização e ajustes**, configure nome, logo, cores e tela de login. A fonte Montserrat acompanha o plugin, sem requisição a serviços de fontes.
5. Em **Equipe**, cadastre os integrantes e atribua um ou mais perfis. Administradores também podem editar as permissões de cada perfil e criar perfis personalizados.
6. Cadastre o primeiro visitante. O cartão entra automaticamente na lista inicial do quadro.

Shortcode: `[visitas_portal]`. Uma página que contenha esse shortcode também usa o portal completo, com layout próprio. O endereço alternativo `/?visitas_app=1` funciona quando a página principal não está disponível. A opção **Usar o portal também como página inicial do site** fica na personalização.

Requisitos: WordPress 6.5+, PHP 8.1+, MySQL/MariaDB compatível com WordPress e OpenSSL para proteger credenciais. Tabelas transacionais usam o prefixo do próprio WordPress; prefira InnoDB. Instalação por site; ativação em rede multisite não é suportada nesta versão.

## Atualizações pelo WordPress

A partir de 1.0.2, o plugin consulta as versões publicadas no repositório público `paulommaeda/visitas` e integra a oferta ao mecanismo nativo do WordPress. Novas versões aparecem em **Plugins** e **Painel → Atualizações**; clique em **Atualizar agora**. O administrador pode optar pelas atualizações automáticas no controle padrão do WordPress.

Para consultar imediatamente, clique em **Verificar atualizações** na linha do plugin ou em **Visitas → Atualizações**. Essa ação consulta apenas o Visitas e mantém as informações dos outros plugins. Não exige token do GitHub. A hospedagem precisa conseguir acessar `raw.githubusercontent.com` por HTTPS.

O resultado da consulta fica em cache por até seis horas, respeitando a frequência de verificação do próprio WordPress. A verificação manual ignora esse cache. Falhas de consulta são temporárias e não alteram o plugin instalado. O ZIP tem pasta `visitas/` e seu SHA-256 é conferido antes de substituir os arquivos. Uma atualização não remove quadros, cartões, usuários ou configurações. Faça backup antes de atualizar.

O canal recebe somente as versões do manifesto `dist/update.json` e os pacotes `dist/visitas-VERSAO.zip`; alterações de código no GitHub sem novo pacote publicado não são oferecidas como atualização. Não há envio de dados de visitantes, SMS ou credenciais ao GitHub.

## Dashboard de acompanhamento

Abra **Dashboard** no menu do portal. Administradores, Pastor e Coordenação recebem acesso por padrão. Em **Equipe → Permissões dos perfis**, a permissão **Consultar dashboard de acompanhamento** pode ser concedida ou removida. Ela respeita o acesso aos quadros privados e aos cartões de cada perfil; não concede acesso ao wp-admin.

Filtre por mês e quadro ou marque **Todos os meses**. Cartões arquivados ficam de fora, a menos que você marque **Incluir arquivados**. Cada cartão representa uma pessoa; cadastros duplicados em cartões diferentes são contados separadamente.

| Indicador | Critério |
| --- | --- |
| Visitantes no período | Data da primeira visita; quando não preenchida, data do cadastro no fuso horário do WordPress |
| Pessoas acompanhadas | Pessoas com ao menos uma interação ou visita realizada no mês, inclusive quem chegou antes; várias visitas ao mesmo cartão contam uma pessoa |
| Visitas registradas | Número de registros do tipo visita realizada; o indicador também informa o número de pessoas distintas visitadas |
| Prazos em atraso | Visitantes do período com prazo vencido e acompanhamento ainda não concluído, considerando a data atual |
| Pessoas por etapa | Lista atual dos visitantes do período; não é uma fotografia histórica da posição do cartão naquele mês |

Clique em um indicador ou etapa para ver a lista de pessoas, com busca por nome, telefone e e-mail e paginação. Abra o visitante para consultar contatos, responsável, etapa, observações do cadastro e todo o histórico de visitas e interações. O gráfico mensal compara visitantes e pessoas acompanhadas em seis meses, e os últimos registros permitem abrir diretamente o visitante.

No modal, **Registrar visita** salva a data da visita realizada e suas observações, com autor e horário do registro. Visitas retroativas entram no mês da data informada. O histórico inclui também as interações das versões anteriores. Para registrar, é necessária a permissão de comentários e acesso de escrita ao quadro; observadores consultam os registros sem alterá-los. O mesmo registro de visita fica disponível na aba **Interações e histórico** do cartão completo. Nenhuma mensagem é enviada ao registrar uma visita.

## Quadros, listas e cartões

- Crie vários quadros, com descrição, cor e visibilidade próprios.
- O quadro inicial vem com as etapas **Novos visitantes**, **Primeiro contato**, **Em acompanhamento** e **Conectados**. Todas podem ser renomeadas e reordenadas.
- Defina qual lista recebe novos visitantes nas configurações do quadro.
- Quadros da equipe estão disponíveis a usuários autorizados do portal. Quadros privados exigem vínculo de membro, autoria ou a permissão de supervisão global.
- Membros podem ser **Observador**, **Participante** ou **Gestor do quadro**. O perfil WordPress continua definindo as operações permitidas. Observadores do quadro têm acesso de leitura; administradores mantêm acesso pleno.
- Arraste cartões no desktop para mudar etapa e posição. No celular, altere o campo **Lista** no cartão.
- Ao soltar, o cartão muda de lugar imediatamente e uma única solicitação salva a movimentação em segundo plano. O quadro e sua rolagem são preservados. Uma falha restaura a posição anterior; conflitos de edição consultam a versão atual do cartão.
- O cartão guarda nome, telefone, e-mail, nascimento, data da visita, origem, endereço, observações, etiquetas, pessoa responsável, data inicial, prazo, conclusão e arquivamento.
- Atribuições exigem que a pessoa tenha acesso de edição ao quadro. Em quadros privados, adicione a pessoa como membro antes de atribuir o cartão.
- Há busca por nome, contato, origem e etiquetas, filtros por responsável e prazo, e visualização de arquivados.
- Interações e movimentações ficam no histórico. Edições concorrentes são detectadas; um cartão desatualizado precisa ser reaberto antes de salvar.
- Excluir um cartão é permanente e remove também seu histórico e registros de comunicação locais. Uma mensagem já aceita pelo serviço externo pode continuar sendo enviada por ele.
- Uma lista só pode ser excluída se estiver vazia e não for a lista inicial. Um quadro só pode ser excluído quando não contém cartões, inclusive arquivados.

### Modelos e atributos por quadro

Em **Configurações do quadro**, crie campos de texto, texto longo, número, data, seleção e sim/não. Os campos podem ser obrigatórios. Novos campos obrigatórios são exigidos no próximo salvamento completo do cartão. Remover um campo deixa de exibi-lo; uma edição posterior pode remover seu valor do cartão.

Crie modelos de checklist com um passo por linha. Todos os modelos do quadro são copiados para novos cartões. Depois, cada cartão pode ter seus próprios checklists e itens, inclusive aplicar um modelo adicional. Alterar um modelo não altera os checklists dos cartões existentes.

## Acesso e perfis

| Perfil inicial | Acesso padrão |
| --- | --- |
| Administrador WordPress | Portal completo, configurações, credenciais e editor de permissões; acesso ao wp-admin |
| Pastor — Visitas | Todos os quadros, inclusive privados; gestão de quadros, cartões, equipe e comunicações |
| Coordenação — Visitas | Gestão de quadros permitidos, cartões, equipe e comunicações |
| Recepção — Visitas | Cadastro e edição de visitantes; visualização dos cartões dos quadros permitidos; interações |
| Acompanhamento — Visitas | Cartões atribuídos a si ou criados por si, com edição, interações e comunicações |
| Observador — Visitas | Leitura dos cartões dos quadros permitidos |

Os perfis são funções reais do WordPress. Suas permissões do portal podem ser editadas em **Equipe → Permissões dos perfis**. Apenas administradores alteram essa matriz e as configurações globais. A gestão da equipe permite combinar perfis; gestores não podem conceder permissões superiores às próprias. O portal não cria administradores WordPress.

O wp-admin é exclusivo do papel `administrator` e de superadministradores multisite. Usuários comuns são redirecionados ao portal e não veem a barra administrativa. Endpoints técnicos de AJAX continuam disponíveis sob as verificações próprias do WordPress e de cada plugin.

O login, a recuperação e a redefinição de senha usam telas do sistema. Contas sem permissão de portal não conseguem entrar enquanto o plugin está ativo; isso é adequado para uma instalação dedicada ao sistema. Em **Meu perfil**, cada integrante pode atualizar seus dados e senha. Alterar e-mail ou senha exige a senha atual.

## Identidade visual e login

Padrão da CCShalom: azul institucional `#000A52`, azul principal `#001BE1`, branco e Montserrat. A logo oficial pode ser enviada em PNG, JPG ou WebP nas configurações; enquanto não houver uma logo definida, o nome da organização identifica a interface.

Personalize nome do sistema e organização, frase institucional, logo, favicon, cores principais, menu, fundo, superfícies e texto, fonte personalizada, arredondamento e densidade. A tela de login aceita cor sólida, degradê ou imagem, além de título, descrição, cor do formulário e texto. Os arquivos de imagem enviados não devem conter dados de visitantes; ficam na biblioteca de mídia pública do WordPress.

## Notificações

E-mail e SMS vêm **desativados**. Configure e habilite em **Personalização e ajustes → E-mail e SMS**.

- Boas-vindas ao visitante, quando a automação estiver habilitada e o canal autorizado no cartão.
- Aviso à pessoa responsável ao atribuir o cartão.
- Lembrete de prazo, com antecedência configurável em horas. Há também notificações internas no sino.
- Mensagens manuais pela aba **Enviar mensagem** do cartão, para usuários com permissão.
- Cada integrante configura o celular e suas preferências em **Meu perfil**. SMS para a equipe exige adesão explícita nesse perfil.
- As preferências e o acesso são verificados novamente antes de despachar uma mensagem da fila.
- Assunto e corpo são editáveis, com `{nome}`, `{igreja}`, `{responsavel}`, `{quadro}`, `{prazo}` e `{link}`.

E-mail usa `wp_mail()` e o serviço de e-mail do WordPress, inclusive plugins SMTP já configurados. O estado **Aceito para envio** indica aceitação pelo serviço, sem comprovar entrega na caixa do destinatário.

### SMS Gateway for Android

Documentação oficial: https://docs.sms-gate.app/

1. Instale o aplicativo SMS Gateway no Android, com um chip apto a enviar SMS.
2. Para um WordPress hospedado na internet, use **Cloud Server** no aplicativo e mantenha-o **Online**.
3. Copie o usuário e a senha gerados para as configurações do plugin. Se necessário, informe o ID do dispositivo e o chip SIM.
4. Use **Testar envio de SMS**, na mesma aba: informe um número com DDD, escreva a mensagem e clique em **Enviar SMS de teste**. O botão salva os dados do gateway preenchidos acima e faz o teste imediatamente, mesmo com as automações SMS desativadas e sem cadastrar um visitante.
5. Confira o resultado em **Comunicações**. A conexão real com o seu aparelho precisa ser validada após configurar as credenciais.

O resultado do teste mostra número normalizado, identificador da mensagem, retorno HTTP e estado do gateway. **Consultar entrega** busca a confirmação do aparelho. Aceitação e entrega são exibidas separadamente; erros de autenticação e conexão aparecem no próprio formulário. Os testes também ficam em **Comunicações**. Um teste sem confirmação não é reenviado automaticamente; a consulta de status e a repetição da mesma solicitação preservam seu identificador.

Para começar a usar as automações após validar o teste, habilite o canal SMS e salve as configurações. Comunicações para visitantes continuam exigindo a autorização no cartão.

| Modo | URL base a informar | Recurso usado pelo plugin |
| --- | --- | --- |
| Nuvem pública | `https://api.sms-gate.app/3rdparty/v1` | `POST /messages` e `GET /messages/{id}` |
| Servidor privado | Base HTTPS da sua instalação, com o prefixo da API | `POST /messages` e `GET /messages/{id}` |
| Local no Android | Ex.: `http://192.168.1.20:8080` | `POST /message` e `GET /message/{id}` |

Não inclua `/message` ou `/messages` na URL base. No modo local, o servidor WordPress deve alcançar a rede do Android. O modo local permite HTTP; use-o somente numa rede controlada. Nuvem pública e servidor privado exigem HTTPS. O telefone permanece responsável pelo envio e pelo consumo de SMS do chip.

Integração: autenticação Basic, JSON `textMessage.text`, destinatários E.164, `deviceId` opcional, `simNumber`, prazo de expiração e relatório de entrega. O identificador de cada SMS é preservado nas novas tentativas para evitar duplicações. Não há webhook público nesta versão; o plugin consulta o estado pela API.

### Fila e estados

O WP-Cron processa até 10 mensagens a cada minuto e busca lembretes por hora. Erros transitórios têm até quatro tentativas com intervalos crescentes. A consulta de status lê até cinco SMS por execução, além da consulta manual em Comunicações. Estados: na fila, processando, aceito, enviado, entregue, falhou ou cancelado. Uma mensagem aceita pelo gateway não é reenviada por falta de confirmação de entrega.

O WP-Cron depende das visitas ao site. Para envio pontual em produção, configure o cron da hospedagem para executar `wp-cron.php` a cada minuto, ou use `wp cron event run --due-now` com WP-CLI. Lembretes consideram cartões ativos, não concluídos, com prazo entre sete dias atrás e a antecedência configurada. Textos longos ou com acentos podem ocupar mais de um SMS conforme o aparelho e a operadora.

## Dados, segurança e manutenção

O plugin usa tabelas próprias `visitas_boards`, `visitas_lists`, `visitas_cards`, `visitas_members`, `visitas_activity` e `visitas_notifications`, com o prefixo da instalação. Não usa posts públicos para visitantes. As rotas REST exigem autenticação, nonce para sessões do navegador, permissão de operação e escopo do quadro/cartão. Credenciais do gateway não são enviadas ao navegador e são cifradas com OpenSSL e as chaves do WordPress. Ao trocar as salts do WordPress, salve novamente a senha do gateway.

O portal envia cabeçalhos de conteúdo privado e não indexável. Exclua a página do portal e `?visitas_app=1` do cache da hospedagem/CDN. Faça backup do banco, configurações e uploads antes de atualizações.

Desativar ou remover o plugin preserva os dados por padrão. Para remover também tabelas, configurações, página e perfis criados pelo plugin, defina `VISITAS_DELETE_DATA` como `true` no `wp-config.php` **antes** de excluir o plugin. Isso é irreversível.

## Desenvolvimento e validação

Não há dependências de frontend nem etapa de compilação. PHP, JavaScript e CSS são locais. Para gerar o ZIP:

```bash
python3 bin/build.py dist/visitas-1.1.0.zip
```

O script extrai a versão e os requisitos dos cabeçalhos e gera também `dist/update.json`, com URL do ZIP, histórico e SHA-256. Publique o código, o ZIP e o manifesto juntos em `main`. Mantenha os ZIPs anteriores disponíveis para instalações que ainda tenham uma oferta em cache. Não altere um ZIP já publicado; aumente a versão para cada lançamento.

Testes devem rodar em um WordPress descartável. Nunca use dados reais ou uma instalação de produção. O teste de integração bloqueia envios reais de e-mail e SMS e remove suas próprias fixtures:

```bash
VISITAS_TESTING=1 VISITAS_WP_ROOT=/caminho/wordpress php tests/integration.php
VISITAS_TESTING=1 VISITAS_WP_ROOT=/caminho/wordpress php tests/dashboard.php
```

O teste de interface `tests/browser.cjs` exige Playwright, Chromium e um WordPress descartável com o plugin ativado. Configure `TEST_BASE_URL`, `TEST_ADMIN_USER` e `TEST_ADMIN_PASSWORD`. Opcionalmente, informe `VISITAS_PHP`, `VISITAS_PHP_INI` e `VISITAS_WP_ROOT` para o teste iniciar o servidor PHP. `CHROMIUM_PATH` e `PLAYWRIGHT_PATH` permitem usar runtimes já instalados.

`tests/dashboard-browser.cjs` usa o mesmo ambiente para validar filtros, indicadores, listas por etapa, busca, modal de visitantes e registro de visitas em desktop e celular. O teste cria dados fictícios de seis meses. `tests/dashboard.php` valida os números, a paginação, as permissões, a migração dos perfis e os limites de mês no fuso horário do WordPress.

Ao iniciar o servidor PHP, o teste carrega `tests/mock-services.php` para bloquear envios reais e simular as respostas do SMS Gateway. Se usar um servidor externo ao teste, carregue esse arquivo como mu-plugin nesse WordPress descartável e configure `VISITAS_TESTING=1` no processo PHP.

`tests/updates.php` valida o canal de atualização e executa uma substituição real dos arquivos com `Plugin_Upgrader`. Use uma **cópia descartável** do WordPress e do plugin, sem link simbólico para o repositório. Além de `VISITAS_TESTING=1` e `VISITAS_WP_ROOT`, informe `VISITAS_TEST_PACKAGE` com um ZIP de teste cuja versão seja o próximo patch da instalada. O teste intercepta todas as requisições externas e verifica a preservação de ativação, quadros, cartões e configurações.

`tests/admin-updates.cjs` valida o mesmo fluxo pela interface do WordPress, inclusive o clique em Atualizar agora. Use essa cópia descartável, os parâmetros PHP/Playwright acima, `VISITAS_TEST_PACKAGE` e `VISITAS_UPDATE_FIXTURE` apontando para um manifesto JSON local da versão de teste. Os arquivos de teste não entram no ZIP distribuído.

Licença GPL-2.0-or-later. Montserrat: SIL Open Font License, em `assets/fonts/OFL.txt`.

Validação da versão 1.0.0: PHP 8.3.6, WordPress 7.1.3 com o driver oficial SQLite em ambiente descartável; 64 verificações de integração aprovadas. Interface validada em Chromium, desktop (1440 px) e celular (390 px), incluindo login, cadastro, movimentação, checklist, histórico, configurações, negação de acesso sem nonce e redirecionamento de não administradores do wp-admin. Os testes do gateway utilizaram respostas simuladas; a conexão com o aparelho de produção exige as credenciais da instalação.

Validação da versão 1.0.1: 88 verificações de integração aprovadas. O navegador confirmou o arraste antes da conclusão da requisição, uma única gravação sem recarregar o quadro, preservação da rolagem e restauração em falhas. O formulário de SMS foi validado em desktop e celular, incluindo autenticação recusada, aceitação do teste e consulta de entrega, com os serviços externos simulados.

Validação da versão 1.0.2: 88 verificações de regressão e 36 do atualizador aprovadas em WordPress 7.1.3/PHP 8.3.6. Foram testados cache, indisponibilidade, manifesto inválido, permissões, nonce, integridade do pacote e atualização nativa preservando os dados. O navegador validou a tela em desktop e celular, consulta manual, detalhes da versão e Atualizar agora até a reabertura do portal, com GitHub e demais serviços simulados.

Validação da versão 1.1.0: 88 verificações de integração e 66 do dashboard aprovadas em WordPress 7.1.3/PHP 8.3.6. Os testes de interface cobriram filtros mensais, contagem de pessoas sem duplicar visitas, modal, histórico, cartão completo e salvamento de visitas no celular. A atualização nativa desde o ZIP publicado 1.0.2 foi validada em instalação isolada, preservando cartões, checklists, quadros, interações, configurações, ativação e permissões já personalizadas. Todos os serviços externos foram interceptados nos testes.
