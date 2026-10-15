# Orientações para manutenção

- Este repositório é um plugin WordPress; mantenha o portal independente do tema e os ativos locais, sem ferramentas de compilação obrigatórias.
- O plugin é white label. Defaults da CCShalom ficam em `Settings::defaults()`; não fixe a marca em novas telas.
- Todas as operações de visitante devem passar pelo escopo de quadro/cartão em `Access`, além da capacidade correspondente. Novas rotas REST precisam de autenticação e autorização no servidor.
- Não conceda privilégios administrativos WordPress por meio do editor de perfis. O acesso ao wp-admin é exclusivo de administradores.
- Datas com horário são persistidas em UTC; datas sem horário não devem ser deslocadas por timezone. Telefones são normalizados para E.164.
- Modelos pertencem ao quadro. Cartões recebem cópias independentes dos checklists; editar modelos não pode apagar progresso existente.
- E-mail e SMS não devem ser enviados durante testes. Use as interceptações de `tests/integration.php` em um WordPress descartável. Nunca inclua credenciais reais, banco, uploads ou dados de visitantes em commits.
- Preserve idempotência dos SMS, o reexame de consentimento e acesso antes do envio e a distinção entre aceitação e entrega. Nunca reenvie uma mensagem apenas por faltar relatório de entrega.
- Depois de alterações, valide sintaxe PHP/JS e execute os testes pertinentes. Mudanças de interface devem ser revisadas em desktop e celular.
- O ZIP instalável é gerado por `python3 bin/build.py dist/visitas-VERSAO.zip`. Mantenha versão, documentação e artefato consistentes. `tests`, `bin`, `.git` e `dist` não entram no pacote.
