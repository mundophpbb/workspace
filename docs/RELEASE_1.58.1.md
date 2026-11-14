# Workspace IDE 1.58.1 — Atualização visual

Atualização focada na aparência do editor, dos formulários e dos modais, baseada na versão 1.58.0.

## Alterações

- Nova camada CSS `workspace_modern.css`, carregada depois dos estilos existentes para padronizar os campos sem reescrever componentes antigos.
- Inputs, selects e áreas de texto com cantos arredondados, melhor espaçamento, contraste e estado de foco visível.
- Modais, abas, menu e cartões com superfícies, bordas e espaçamentos harmonizados, incluindo telas menores.
- Remoção da apresentação de tooltips flutuantes e `title` na interface do Workspace, mantendo rótulos ARIA para controles interativos.
- Campos internos do Ace Editor excluídos das regras de formulários para não interferir na edição de código.
- Suporte a `prefers-reduced-motion`.

## Compatibilidade

- Não há migrations novas ou alterações nos endpoints, permissões, dados e ações.
- Após atualizar, limpe o cache do phpBB e do navegador para carregar o novo CSS e o JavaScript.
- A mudança afeta somente a interface do editor e de suas janelas; não substitui testes visuais nos navegadores e temas efetivamente utilizados.

## Verificações

- 89 testes automatizados (PHP/JavaScript) aprovados, incluindo teste novo de visual e acessibilidade.
- Sintaxe de 141 arquivos PHP e 28 JavaScript verificada.
- Ainda não testado visualmente em instalação real do phpBB.
