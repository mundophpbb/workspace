## 1.58.4 — Correção do escopo de validação para distribuição

O validador distingue os arquivos de desenvolvimento (`tests/` e `tools/`) dos arquivos de distribuição ao verificar namespaces e escaping específicos do phpBB. O código de produção continua sujeito às verificações. Testes e utilitários permanecem no pacote-fonte completo, mas não integram o ZIP candidato ao phpBB.com. Avisos sobre SQL, estilo e comentários não são suprimidos. Sem alterações de banco, permissões ou migrations.

## 1.58.3 — Revisão de compatibilidade e validação

A verificação de dependências usa tokens de PHP para reconhecer construtores reais, ignorando exemplos embutidos em strings; a regra de superglobais também usa tokens e desconsidera fixtures de teste e ferramentas CLI. A detecção de chaves de tradução não confunde dados de testes com uso real na interface. O mock visual de templates utiliza `utf8_htmlspecialchars()` compatível com phpBB. Para submissão ao phpBB.com, utilize o ZIP de distribuição sem `tests/`, `tools/` e `docs/`; mantenha o pacote-fonte completo para desenvolvimento. Não altera banco, permissões nem migrations. O EPV oficial continua exigindo execução externa quando `proc_open()` está indisponível.

## 1.58.1 — Modernização visual do editor e modais

Formulários, abas, modais e navegação receberam estilo mais consistente, com campos e foco modernizados. Tooltips visuais redundantes foram desativados, preservando rótulos de acessibilidade. Consulte `docs/RELEASE_1.58.1.md`. Sem migrations.

## 1.54.0 — Inicialização sob demanda de ferramentas

Ferramentas abertas manualmente agora adiam o registro dos próprios eventos e a inicialização até o primeiro clique. O núcleo do editor, o Radar e demais serviços automáticos permanecem ativos desde o início. Não se trata de download sob demanda: os arquivos JS continuam incluídos no HTML por compatibilidade. Nenhuma migration nova.

## Workspace IDE 1.53.0 - Busca no menu Ferramentas

A barra Ferramentas agora oferece busca local por nome de ferramenta, inclusive sem acentos, sem carregar dados remotos. A busca preserva ACLs e as acoes originais. O reposicionamento do dropdown em eventos de scroll e resize e consolidado por frame. Nao ha migrations novas. Veja `docs/RELEASE_1.53.0.md`.

## 1.45.0 — Recuperação seletiva de backups ZIP

Na **Central de Projetos e Lançamentos**, exporte um backup ZIP do projeto e use **Verificar ZIP**. Após a verificação SHA-256 do manifesto, selecione explicitamente os arquivos desejados, clique em **Preparar restauração selecionada** e confirme somente depois de revisar o aviso. Para aplicar, o navegador envia o mesmo ZIP novamente; o servidor valida hashes, projeto, autorização, CSRF, revisões e bloqueios. A aplicação usa transação única e cria um checkpoint `pre_restore` antes das alterações. Apenas caminhos selecionados podem ser adicionados, alterados ou removidos. O registro do checkpoint e as alterações são revertidos juntos em caso de falha. A restauração não usa URLs externas, nem executa arquivos.

**Limites:** máximo de 350 arquivos e 8 MiB descompactados; ZIP até 10 MiB. Precisa da extensão PHP `ZipArchive`. O pacote deve pertencer ao mesmo `project_id`; transferência entre instalações permanece fora do escopo. A restauração de arquivos não substitui backups externos completos do fórum, e permissões e bloqueios existentes continuam válidos. A confirmação não pode ser reutilizada após mudanças no projeto ou na seleção. Nova versão sem migrações de banco de dados; a migração `v121` da 1.41.1 continua incluída.

## 1.40.0 — Comparação e relatórios de estatísticas

A Central de Projetos oferece comparação de atividades e lançamentos entre blocos consecutivos de três meses, distribuição por ação registrada (até oito tipos mais outros) e exportação CSV com marcações de amostras parciais. Mês atual incompleto, sem pontuação individual.

## 1.37.0 — Central de projetos e lançamentos

Em **Ferramentas → Histórico → Central de projetos e lançamentos**, consulte, em uma única tela, os projetos autorizados ao usuário, a última versão registrada, a data, o nome do ZIP de referência, a quantidade de lançamentos e a quantidade de arquivos armazenados. Pesquise por nome, filtre projetos sem registros e abra diretamente um projeto. O painel é somente leitura e não depende do GitHub. "Sem lançamento registrado" não prova ausência de publicação externa.

A consulta utiliza POST com CSRF, autenticação e ACL por projeto. Retorna no máximo 200 projetos do conjunto de projetos administrados ou dos quais o usuário é proprietário/membro. Quando há mais candidatos do que o limite, a interface informa truncamento. Nenhuma migração nova foi adicionada. Sem histórico do 1.35.0, a tabela de lançamentos deve ser criada pela migração v120.

## 1.36.0 — Fluxo de publicação integrado

O assistente de lançamento, após aprovação da validação e do EPV oficial, permite baixar o ZIP e abrir o histórico de lançamentos com versão e nome do pacote preenchidos. O servidor verifica novamente o fingerprint do conteúdo antes de registrar o snapshot. O usuário confirma explicitamente que recebeu o ZIP; o próprio ZIP não é arquivado nem enviado ao GitHub. A publicação remota continua manual.

## Novidade 1.33.0 — Painel de Saúde GitHub

Em Ferramentas → GitHub e repositórios, use o Painel de saúde para consultar manualmente o último commit da referência vinculada, a última Release publicada e a última execução de Actions do repositório (que pode pertencer a qualquer branch). Um quarto cartão informa se o SHA registrado na última importação coincide com o SHA em cache do repositório. **Não é um diff dos arquivos atuais.** Cada consulta consome cota compartilhada da integração; resultados indisponíveis são exibidos como desconhecidos, nunca como saudáveis. Nenhuma escrita ou migração nova.

# Workspace IDE 1.29.0 — Releases GitHub e Comparação de Versões

## GitHub public repositories (Workspace 1.27.0)

Optional and **off by default**. Requires PHP cURL with HTTPS support; manual ZIP imports additionally require PHP ZipArchive. GitHub API requests are unauthenticated and limited to 40 requests per hour shared across the forum (including download requests).

1. In **ACP > Extensions > Workspace IDE > Settings**, enable **GitHub public repositories**. Optionally enable **manual import from GitHub** independently. Both are off by default.
2. Sign in and open a Workspace project with edit permission. In **Tools > GitHub and repositories**, the project manager can link any public `owner/repository` or `https://github.com/owner/repository` and an optional branch/ref. Each project stores its own link. The GitHub account is never hardcoded.
3. Click **Check updates** to refresh the current commit SHA (cached/rate limited). Click **Open on GitHub** to view the repository externally.
4. If imports were enabled and the user has edit, upload and rename/move permissions, click **Preview import**. The Workspace reads a bounded ZIP snapshot pinned to the stored commit SHA and lists new/changed/unchanged files. The remote package must have a valid phpBB extension `composer.json`, and must match the existing project extension when present.
5. Click **Import** after reviewing the preview and confirming. The snapshot is re-downloaded and all local file revisions and locks rechecked. All modifications use one database transaction with file history; untouched local-only files are **never automatically deleted**.

Security: no personal access tokens, private repositories, GitHub pushes/commits, OAuth, background jobs or automatic synchronization. GitHub availability and cURL/ZipArchive support must be checked on the actual hosting environment. The GitHub ZIP must stay within the configured size limits. If you disable these options in the ACP, GitHub tools are unavailable; saved links are retained.

The GitHub integration adds migration `v119` with two tables, for per-project links and a server-wide request budget. Re-enable the extension after updating so phpBB applies the migration. **Back up the forum database before updating.**

---

# Workspace IDE 1.26.3 — Pesquisa mais compacta na página pública

Correção exclusivamente visual no catálogo público: a pesquisa ocupa até 320 px no desktop, com altura fixa de 40 px, e não se estica junto dos filtros. Em telas de até 650 px, usa 100% da largura disponível e 44 px de altura para facilitar o toque. Os filtros foram alinhados à mesma altura. A busca, a paginação e as permissões não foram modificadas.

**Atualização:** substitua os arquivos da extensão, limpe o cache do phpBB e atualize o navegador (Ctrl+F5). Não há migrações de banco de dados. Esta revisão preserva todas as funcionalidades da 1.26.2.

---

# Workspace IDE 1.26.2 — Página pública e ACP

Esta atualização acrescenta uma **página de catálogo para visitantes** no endereço normal do Workspace (quando nenhum projeto está aberto). A página mostra **somente os projetos que o visitante tem permissão para visualizar**, com busca, ordenação, paginação e layout responsivo em cards ou lista. O download aparece apenas se a permissão específica autorizar. Abrir um projeto leva ao **visualizador existente, somente leitura**. Usuários autenticados continuam com a IDE tradicional; também podem abrir o catálogo pelo atalho na barra ou por `?catalog=1`.

No **ACP → Extensões → Workspace IDE → Configurações do Workspace**, há uma nova seção **Aparência e página pública** para personalizar título, descrição, mensagem para visitantes, tema automático/claro/escuro, layout padrão, ordenação, quantidade de projetos por página (6–48) e exibição de autor, data de atualização e total de arquivos. Se o catálogo for desativado, os visitantes voltam a ver a interface da IDE como antes, respeitando o modo somente leitura. As opções de idioma estão disponíveis em português e inglês.

O catálogo é montado com uma filtragem explícita de ACL por projeto **antes** de consultar seus metadados. Nunca carrega o conteúdo dos arquivos para compor a listagem. Os URLs de abertura e download continuam sujeitos às verificações de permissão já existentes. A página usa CSS isolado do editor e não executa JavaScript de terceiros.

**Instalação:** faça backup; desative a extensão sem apagar os dados; substitua a pasta `ext/mundophpbb/workspace`; reative, limpe o cache do phpBB e atualize o navegador. **Sem migrações de banco**: as novas opções utilizam a tabela `config`, com valores padrão válidos para instalações atualizadas. Confirme a visualização como convidado e o funcionamento da IDE autenticada num fórum de testes antes de publicar.

---

# Workspace IDE 1.26.1 — Correção de template na Documentação Automática

**A funcionalidade prevista originalmente para a 1.23.0 foi incluída na atualização 1.26.0** para manter a numeração crescente e preservar o Painel de Qualidade (1.24.0) e a Pré-visualização de Templates (1.25.0).

**Onde encontrar:** **Ferramentas → Histórico e changelog → Documentação automática**.

- Gera um rascunho Markdown com `composer.json`, requisitos declarados, instalação, rotas, serviços, migrações, eventos, traduções, templates, testes e instruções genéricas de atualização.
- Seleção de português brasileiro ou inglês, prévia editável, cópia e download.
- O destino padrão é `docs/README_GERADO.md`, para não substituir o `README.md` existente sem aviso. Também é possível escolher `README.md` e confirmar explicitamente sua substituição.
- A alteração de um arquivo existente é registrada no histórico de versões; o projeto é protegido por transação, controle de revisão, bloqueios e token CSRF.
- A prévia se torna inválida quando qualquer arquivo salvo muda. Projetos importados de ZIPs com subpastas preservam o caminho original do `composer.json`.
- Visitantes e usuários somente leitura **não** podem usar o gerador. Não há execução de código do projeto nem gravação durante a prévia.

**Limites:** leitura de até 1.000 arquivos, 24 MiB de conteúdo acumulado e 2 MiB por arquivo (limites do leitor compartilhado). Rascunhos de até 128 KiB. A ferramenta não comprova que funcionalidades, testes, endpoints ou migrações funcionem: descreve apenas arquivos e metadados encontrados. É obrigatório revisar o documento antes de publicar.

**Instalação/upgrade:** sem migrações novas. Faça backup; desative sem excluir os dados; substitua os arquivos; reative e limpe o cache do phpBB e do navegador.

---

# Workspace IDE 1.20.0 — Auditor Inteligente de Segurança

Acesse **Ferramentas → Auditor Inteligente de Segurança** com um usuário autenticado que
possua permissão de edição no projeto. O auditor examina **somente os arquivos salvos**
(no banco de dados), sem executar o código analisado, e apresenta possíveis riscos com:

- severidade (**alta/média/baixa**) e nível de confiança (**alta/média/baixa**);
- categoria (SQL, saída HTML/XSS, CSRF, autorização, upload, execução, entrada,
  credenciais e TLS);
- arquivo e linha clicáveis para abrir o trecho no editor Ace;
- recomendação de revisão, filtros e exportação de relatório TXT sem trechos de código
  ou valores de possíveis segredos.

O endpoint exige **POST com token CSRF**, autenticação, acesso global ao Workspace e
permissão de **edição no projeto**. Visitantes e usuários somente leitura não acessam
a auditoria. A execução é somente leitura. São analisados até **450 arquivos de código**,
**256 KiB por arquivo**, **6 MiB por solicitação** e **180 achados** (máximo de 30 por
arquivo). Bibliotecas, arquivos de teste, fontes não relevantes e arquivos grandes
são ignorados; a interface informa quando o exame foi parcial. O fluxo reaproveita
o limite e a normalização de caminhos da inspeção de arquitetura.

**Limites importantes:** os resultados são indícios heurísticos, não vulnerabilidades
confirmadas. Funções auxiliares, fluxos entre arquivos e dados gerados dinamicamente
podem escapar das regras; algumas operações seguras podem gerar avisos de revisão.
A ausência de achados **não certifica segurança**. Inspecione o código e faça testes
em um ambiente de homologação. O Auditor não substitui EPV, testes ou pentest.

**Atualização:** sem novas migrações de banco. Faça backup, desative a extensão no
ACP sem remover seus dados, substitua os arquivos, reative e limpe cache do phpBB
e do navegador. Todas as funções anteriores permanecem disponíveis.

---

## Workspace IDE 1.19.0 — Gerador de notas de versão

O menu **Ferramentas → Histórico e changelog → Gerador inteligente de changelog** cria um rascunho Markdown comparando os arquivos atuais com um **ponto de restauração** ou com **outro projeto autorizado que represente uma versão anterior**. As mudanças são classificadas como arquivos adicionados, modificados e removidos, com agrupamentos por estrutura (migrações, configurações, idiomas, testes, interface, código). Algumas mudanças literais em `composer.json`, serviços e rotas recebem detalhes extras; o sistema **não deduz** a intenção das mudanças ou afirma automaticamente que houve correções.

O usuário autorizado escolhe o checkpoint e a versão, confere a prévia, edita livremente o rascunho, copia ou baixa o `.md` e, se tiver permissão, grava no `CHANGELOG.md`. Um `CHANGELOG.md` existente é preservado; a nova seção é inserida no topo e a revisão anterior fica no histórico do arquivo. A ferramenta não altera `changelog.txt`. Uma prévia fica inválida quando o projeto muda; visitantes não podem executar o gerador. Não foram adicionadas migrações de banco de dados.

**Limites:** requer uma versão-base (checkpoint ou outro projeto) e suporta até 1.000 arquivos/24 MiB por projeto, 2 MiB por arquivo, na leitura comparativa; checkpoints históricos ainda obedecem aos limites existentes. Rascunho de até 64 KiB e `CHANGELOG.md` consolidado de até 256 KiB. O conteúdo é uma sugestão para revisão humana, não uma certificação de testes ou de lançamento.

# Workspace IDE 1.17.0 — Pontos de Restauração do Projeto

Esta versão acrescenta checkpoints completos dos **arquivos salvos do projeto**, com
prévia antes de restaurar e cópia automática do estado atual durante a recuperação.

**Onde encontrar:** abra um projeto como editor autorizado e acesse
**Ferramentas → Histórico e changelog → Pontos de restauração**.

- Crie pontos nomeados antes de mudanças importantes e consulte os últimos 16.
- Compare o estado atual com um ponto: arquivos modificados, arquivos que serão
  recriados e arquivos posteriores que seriam removidos.
- Para restaurar, são necessárias as permissões de editar, enviar arquivos,
  renomear/mover e excluir. Um arquivo bloqueado por outro colaborador impede
  toda a restauração, inclusive para administradores do projeto.
- Uma prévia deixa de valer se o projeto mudar. A restauração é transacional:
  se uma gravação ou verificação falhar, as mudanças são revertidas.
- Antes de uma restauração bem-sucedida, um ponto `Antes de restaurar #...`
  é guardado automaticamente; ele também pode ser restaurado.
- Limites: 350 arquivos, 2 MiB por arquivo, 8 MiB totais por checkpoint e até
  16 pontos por projeto. Projetos acima dos limites são recusados sem capturas
  incompletas. Pontos antigos são eliminados automaticamente ao exceder 16.
- Os checkpoints incluem **somente arquivos e conteúdos** do projeto, não
  comentários, atribuições de tarefas, permissões, estados de revisão ou
  configurações do fórum. Arquivos adicionados depois do ponto são removidos na
  restauração, junto de seus metadados associados.
- Nenhuma execução de código de extensões é feita pelo checkpoint.

**Atualização de banco obrigatória:** a migração `v118` adiciona as tabelas
`workspace_checkpoints` e `workspace_checkpoint_files`. Faça backup completo
antes de atualizar, desative a extensão **sem excluir dados**, substitua os
arquivos, reative no ACP (para executar a migração) e limpe o cache do phpBB.
Nunca desinstale/exclua os dados da extensão durante a atualização.

---

# Workspace IDE 1.16.0 — Laboratório de Testes phpBB

A versão 1.16.0 inclui um Laboratório com diagnóstico estático indicativo de versões PHP,
lista de observações e geração do arquivo `tests.yml` para execução externa por GitHub Actions
através do projeto oficial `phpbb-extensions/test-framework` (phpBB 3.3.x).

**Onde encontrar:** Ferramentas → Arquitetura e versões → Laboratório de testes. Apenas
editores autenticados e autorizados podem consultar a ferramenta. Nenhum código da
extensão analisada é executado no fórum e o workflow exportado não é instalado
automaticamente. Os testes de compatibilidade reais dependem de um repositório
GitHub externo com o `composer.json` na raiz e de execução na CI.

O Radar, o Gerador Visual, o Assistente de lançamento, o Comparador de Versões,
o Mapa de Dependências e o Simulador de Permissões continuam disponíveis.
Não há novas migrações. Faça backup antes de atualizar.

---

# Workspace IDE 1.13.2 — menus organizados

Esta atualização apenas reorganiza a interface, sem alterar permissões, rotas nem banco de dados.

- **Novo, Abrir e Salvar:** acessos principais na barra.
- **Projeto:** enviar arquivos, importar ZIP, gerenciar o projeto, bloqueios e exportação (quando autorizada).
- **Ferramentas:** busca e substituição, comparação, BBCode, Radar, Gerador e changelog.
- **Validar phpBB:** assistente de lançamento e modalidades do validador.
- **Colaboração e modo foco:** permanecem na barra.

**Onde está Gerar?** Abra um projeto com permissão de editar e criar arquivos e acesse **Ferramentas → Gerar funcionalidade**. Visitantes continuam sem acesso ao Gerador e ao validador.

**Atualização:** faça backup, desative sem excluir dados, substitua os arquivos em `ext/mundophpbb/workspace/`, reative e limpe o cache do phpBB e do navegador. Não há migrações nesta versão.

---

## v1.13.1 - Correção da visibilidade do botão Gerar

Corrigido o envio da permissão `ACTIVE_CAN_RENAME_MOVE` ao template do phpBB.
Na v1.13.0, o botão **Gerar** e sua janela ficavam invisíveis mesmo para
usuários com as permissões necessárias. A versão 1.13.1 mantém a regra:
somente usuários autenticados com **editar** e **renomear/mover** no projeto
ativo podem abrir o Gerador Visual; visitantes continuam somente leitura.
O botão fica na barra de ferramentas superior, ao lado de **Radar**;
em telas estreitas, o texto pode ficar oculto, mas o ícone de varinha permanece.
Após atualizar, limpe o cache do phpBB e recarregue a página.
Não é necessária migração de banco de dados.

## v1.13.0 - Visual feature generator

Available to authenticated project editors who can also create files. Open **Generate**
from the toolbar, choose a scaffold, type a lower-case identifier, preview the changes,
then apply. Scaffolds: controller with route and template; event subscriber;
configuration migration; permission with migration, core.permissions listener and language files; ACP starter module with migration.

The generator requires a valid `composer.json` and uses its vendor/extension name.
It supports projects imported with the `vendor/extension/` outer directory.
Existing YAML and language files are extended only after a preview; no existing file
is overwritten without a matching revision. The database transaction is all-or-nothing.
Changing files after preview invalidates the operation. Existing edit locks and project
permissions are enforced. Visitors cannot generate files. Generated PHP is never executed
by the Workspace. Generated code is a starting point, not a tested complete feature.
After exporting/installing a changed extension, re-enable/refresh it as needed to run
new migrations and purge the phpBB cache for changed services and routes. No Workspace
database migration is required.

## v1.12.0 — Radar de Problemas Inteligentes

O botão **Radar** está disponível para editores autenticados. Faz análise estática de arquivos salvos, sem executar o código, e navega até arquivo/linha no Ace. Atualiza automaticamente depois de alterações persistidas, com filtros por gravidade. Confere sintaxe PHP na versão instalada no servidor, manifesto composer.json, classes de serviços e referências locais de rotas, permissões e chaves de idioma. O processamento é limitado a 300 arquivos e 4 MiB por verificação, com aviso de análise parcial. O Radar não substitui o EPV oficial nem certifica uma extensão para publicação. Visitantes continuam somente leitura. Não são necessárias migrações.

# phpBB Workspace IDE

Collaborative IDE for building phpBB extensions in the browser: shared projects, file versions,
locks, reviews, comments and tasks, plus an optional pre-validation with the official phpBB EPV
to speed up the extension review.

* Requires phpBB 3.3.0 or later and PHP 7.4 or later.
* Languages: English and Brazilian Portuguese.
* License: GPL-2.0-only (see `license.txt`).

## Installation

1. Copy the extension to `ext/mundophpbb/workspace`.
2. In the ACP go to *Customise* > *Manage extensions* and enable *phpBB Workspace IDE*.
3. Grant the `u_workspace_*` permissions (*Users' permissions* > *Workspace*) to the users or groups that may use it.

## Adding files

* Drag files or folders onto the file tree, or use *File* > *Upload Files*.
* **Import ZIP** (*File* > *Import ZIP*, or drop a `.zip` on the file tree) sends a whole extension in one
  request. The archive is read in memory (nothing is extracted to the disk), the extension folder is detected
  from `composer.json`, only the file types accepted by the editor are imported, and a report lists anything
  that was skipped. Existing files are updated and their previous content is kept in the file history.
* After a drag-and-drop upload a report stays on screen listing the files that failed (with the reason),
  with a button to retry them.

## Settings

*ACP* > *Extensions* > *Workspace IDE* > *Editor settings*: editor theme, keyboard mode, font size,
tabs, autocompletion, maximum file size and the official EPV options.

The official EPV runs only if it is installed in `phpBB/tools/epv` (download `phpbb/epv` from GitHub
and run `composer install` there). The extension itself never downloads or installs it. The EPV can be
turned off in the same ACP page.

## Third-party software

* [Ace](https://ace.c9.io/) 1.43.6 (BSD 3-Clause), in `styles/all/template/ace/` (license in `styles/all/template/ace/BSD-3-Clause.txt`).
* PHP Diff by Chris Boulton (BSD 3-Clause), in `lib/` (license in the file headers).

## Security and collaboration (1.10.3)

* Visitors have read-only access; comment mutations can only be performed by authorized editors; review mutations use the existing edit/manage permissions.
* Saves and version restorations require a matching file revision. A conflict does not overwrite the pending changes in the editor.
* Project managers may override file locks; other users must respect the lock in upload, rename, move, delete, restore and bulk operations.
* Complete validation with official EPV requires editing permission and is serialized / rate-limited per forum.
* Exported ZIP paths are normalized, and CSV cells that could execute spreadsheet formulas are escaped.

### Atualização 1.10.4: clareza de acesso e retorno de erro

- Visitantes continuam com acesso estritamente de leitura, sem acesso ao validador.
- O modo somente leitura é indicado na interface para visitantes e usuários sem permissão de edição no projeto ativo.
- Menus de criação/alteração são ocultados para visitantes, e o validador só aparece para editores autorizados.
- Erros de requisição do validador, inclusive erros de permissão e respostas inválidas, não deixam o botão "Validar novamente" permanentemente desativado.
- Nenhuma migração de banco de dados é necessária para esta atualização.

## Release assistant (1.11.0) / Assistente de lançamento

* For an **authenticated editor**, open the **Validate** toolbar menu and choose **Release assistant / Assistente de lançamento**.
* Click **Start release analysis / Iniciar análise**. The wizard forces the **phpBB Extension DB** scope and walks through four steps: structure, official EPV, findings/fixes and export.
* The existing validator provides details for `composer.json`, configuration/routing, language files, migrations and code checks. Safe fixes are still optional; always review them and rerun validation afterward.
* The wizard enables the final **submission ZIP** only after the validator reports no blocking errors, the **official EPV passes**, and the current user also has permission to download the project. If EPV is not installed/enabled/operational, the assistant does **not** label the package ready.
* Download the **validation report (TXT)** and **submission package (ZIP)** separately. The ZIP contains the `vendor/extension/` layout, without inserting the report into the extension. The report includes the extension version and an identifying content fingerprint.
* Before downloading, the UI checks that project files have not changed. The server repeats this fingerprint check while preparing the ZIP. A changed project must be validated again.
* Visitors remain **read-only** and cannot run the validator or prepare release ZIPs. Editors without download permission may run checks, but cannot export a release package.
* No database migration is required. PHP 7.4+ and phpBB 3.3+ remain the declared minimums.

**Note:** An EPV pass is a pre-submission check, not a guarantee of approval by the phpBB Extension Database team. The server's EPV installation is optional for ordinary editor use, but mandatory for the assistant to enable the release-package button.

## 1.14.0: Comparador de versoes e mapa de dependencias

No menu **Ferramentas > Arquitetura**, use **Comparar projetos/versoes** para comparar
DOIS projetos importados no Workspace. O comparador lista arquivos adicionados,
removidos e modificados, com separacao por area (rotas, servicos, eventos,
permissoes, migracoes e idiomas) e visualizacao de diff limitado por tamanho.
Importe cada ZIP/versao em um projeto separado para usa-lo como referencia.

**Mapa de dependencias** representa referencias explicitas detectadas em
`config/services.yml`, `config/routing.yml` e listeners do evento. O grafo e
uma aproximacao estatica, nao uma analise de chamadas em tempo de execucao.
Os recursos nao alteram arquivos e exigem usuario autenticado com capacidade
de edicao em cada projeto consultado. Projetos grandes podem produzir resultados
parciais, sempre sinalizados na interface.


## 1.15.0: Simulador de Permissoes (ACL)

Em um projeto aberto por editor autenticado, acesse **Ferramentas > Arquitetura e versoes > Simulador de permissoes**.
A tela consulta os arquivos **salvos** e lista chaves ACL declaradas em `config/permissions.yml`,
registradas por `permission.add`, exibidas via `core.permissions` e verificadas por chamadas
literais `acl_get*()` no codigo. Para cada chave, mostra o arquivo/linha e permite abrir a origem
no editor, sem descartar alteracoes nao salvas.

Escolha **visitante, registrado, moderador ou administrador** e marque/desmarque as chaves para
montar um **cenario hipotetico**. Os presets sao demonstrativos, NAO refletem as ACL reais do
forum, os grupos, as permissoes locais por forum ou o estado do ACP. O simulador NAO efetua
impersonacao nem modifica usuarios, grupos ou permissoes. A saida e uma estimativa dos valores
de cada chave, nao uma garantia de acesso a qualquer pagina/acao da extensao, que pode aplicar
outras regras e verificacoes. Execute testes reais com contas autorizadas para validar a seguranca.

O analisador e limitado em quantidade e bytes dos arquivos, mostra resultados parciais e nao
interpreta todas as referencias PHP dinamicas. Operacoes: apenas POST com CSRF e edicao
confirmada no projeto; visitantes e leitores ficam impedidos pelo servidor. Nenhuma migracao
de banco de dados e necessaria. Mantenha backups antes da atualizacao.


### 1.16.0 — Laboratorio de compatibilidade

Em Ferramentas → Arquitetura e versões → Laboratorio de testes, analise indicadores estaticos de versao do PHP e crie um arquivo de workflow para GitHub Actions usando o framework de testes para extensoes do phpBB 3.3.x. A analise nao executa os arquivos do projeto; os testes de integracao somente sao executados se o desenvolvedor publicar o workflow no repositorio da extensao e iniciar a CI. Indicadores nao certificam compatibilidade.


## Workspace 1.18.0: pesquisa e refatoração global

Em **Ferramentas → Pesquisa e refatoração global** é possível pesquisar textos literais, filtrar caminhos, visualizar ocorrências com linha e escolher quais arquivos receberão a substituição. A operação exige prévia válida, permissões de edição/substituição, revisão atualizada, ausência de bloqueio por outro colaborador e token CSRF. Atualizações são transacionais e preservam versões anteriores de cada arquivo. Projetos compatíveis com os limites de checkpoints também recebem um ponto automático anterior à refatoração.

A ferramenta **não é uma refatoração semântica AST**: confirme manualmente referências e comentários antes de aplicar. O conteúdo do projeto não é executado. Há limites de análise; resultados incompletos não podem ser aplicados. Nenhuma migração nova de banco é necessária.

## Workspace IDE 1.21.0 — Assistente de Atualização Segura

Em **Ferramentas → Arquitetura e versões → Assistente de atualização segura**, selecione dois projetos que contenham versões **diferentes da mesma extensão**. A análise compara os arquivos **salvos no banco de dados**, usando as mesmas restrições de leitura da ferramenta de arquitetura: até 850 entradas, 256 KiB por arquivo e 12 MiB por projeto. Requer usuário autenticado com **permissão de edição em ambos os projetos** e POST com token CSRF; visitantes não têm acesso.

A ferramenta procura alterações em `composer.json` (versão, dependências e requisitos PHP/phpBB), migrações PHP adicionadas, modificadas ou removidas, instruções potencialmente destrutivas de `update_schema()` e `update_data()`, serviços/rotas removidos e mudanças aparentes de ACL. **Não executa PHP de extensões, não aplica migrações e não altera arquivos ou dados**. Apresenta gravidade, nível de confiança, arquivo e linha quando conhecidos, e pode baixar um relatório TXT com checklist para backup, teste em staging, atualização, validação e recuperação. O clique em arquivo/linha funciona quando a versão nova é o projeto aberto e não há edição local não salva.

O resultado é **heurístico e estático**: arquivos fora dos limites aparecem como cobertura parcial; referências e migrações dinâmicas podem escapar às regras; avisos podem ser falsos positivos. Operações em `revert_schema()` não são tratadas como exclusões da atualização. Nenhuma mensagem indica que uma versão está certificada como segura. **Um backup dos arquivos, sem backup consistente do banco, não desfaz migrações**. Faça atualização e testes de integração em ambiente isolado antes de produção.

Nenhuma migração de banco de dados é necessária para **atualizar o próprio Workspace** de 1.20.0 para 1.21.0. O recurso opera apenas por leitura.


## Workspace IDE 1.22.0 — Gerador de testes PHPUnit

Em um projeto de extensão phpBB, abra **Ferramentas → Gerador de testes PHPUnit** (ou
**Gerar funcionalidade** e selecione um dos três modelos de teste). É necessário
estar autenticado com permissão de **editar e criar arquivos** no projeto. Visitantes
e leitores não podem executar a geração.

- **Serviço:** informe o nome do arquivo em `service/` sem `.php` (por exemplo,
  `gerenciador` para `service/gerenciador.php`). Também procura `service/NOME_service.php`.
- **Controller:** informe o nome base, por exemplo `pagina` para
  `controller/pagina_controller.php`; também aceita `controller/pagina.php`.
- **Permissão ACL:** informe a chave completa, como `u_minha_permissao`, que precisa
  estar declarada em uma migração `permission.add` ou em `config/permissions.yml`.

A ferramenta lê apenas o código **salvo**, confere o namespace da classe, prepara
`tests/service/`, `tests/controller/` ou `tests/permissions/` e, se ainda não existir,
cria `phpunit.xml.dist`. Exibe a lista e o conteúdo completo dos novos arquivos **antes**
da confirmação. Arquivos existentes nunca são sobrescritos; uma revisão alterada no
intervalo, token inválido ou erro de gravação aborta a operação. A criação múltipla
é transacional e registrada no histórico do projeto. Não são executados scripts do
projeto durante a prévia ou aplicação. Arquivos de até 192 KiB podem ser inspecionados;
o exame de migrações possui limites de quantidade e tamanho.

**Limites dos testes gerados:** testes estruturais verificam que a classe pode ser
carregada, que alguns métodos públicos continuam presentes ou que a declaração ACL
está no arquivo. Eles **não testam automaticamente a lógica de negócio, comportamento
HTTP, autorização efetiva, segurança ou cobertura de código**. Os testes correspondentes
são criados com `markTestIncomplete()` de propósito, até que o desenvolvedor acrescente
mocks, fixtures e assertivas relevantes. Um teste incompleto não equivale a um teste
aprovado. Os arquivos gerados devem ser revisados antes de distribuição.

**Execução:** use o framework de testes em um checkout de origem do phpBB 3.3.x com
as dependências de desenvolvimento instaladas. Rode o PHPUnit **fora do servidor de
produção**, ou utilize o workflow do Laboratório 1.16.0 no GitHub Actions. O próprio
Workspace não executa testes PHPUnit nem instala dependências. Arquivos de configuração
PHPUnit já mantidos pelo projeto não são modificados.

Documentação oficial: https://area51.phpbb.com/docs/dev/3.3.x/extensions/tutorial_testing.html

**Atualização 1.21.0 → 1.22.0:** não há migração de banco de dados. Faça backup,
substitua os arquivos da extensão, limpe o cache do phpBB e atualize o navegador.

## Workspace IDE 1.24.0 - Painel de qualidade do projeto

A Documentação Automática, antes reservada como recurso 1.23.0, foi implementada na versão instalável 1.26.0, preservando as versões 1.24.0 e 1.25.0.
A versão 1.24.0 utiliza a base estável da 1.22.0 e acrescenta, em **Ferramentas >
Arquitetura e versões > Painel de qualidade do projeto**, indicadores de segurança,
estrutura, compatibilidade PHP, ACL, testes/CI e manutenção.

O painel reaproveita as análises estáticas existentes (Radar, Auditor de Segurança,
Laboratório de Compatibilidade e Simulador de Permissões). Os resultados são
atualizados a partir dos arquivos salvos no banco, sem executar nem alterar código.
Exibe arquivo e linha para achados selecionados; a navegação para o editor respeita
alterações não salvas. Também permite comparar o projeto aberto com outro projeto
autorizado, exibindo diferenças numéricas sem classificação arbitrária de qualidade.

**Alcance e limitações:** a listagem de testes PHPUnit ou de workflows de CI
indica apenas arquivos presentes. Não indica que testes foram executados, aprovados
ou que exista cobertura medida. Alertas de segurança são heurísticas e podem conter
falsos positivos. Uma contagem menor entre versões pode significar arquivos fora do
escopo, por isso resultados com cobertura parcial são explicitamente sinalizados.

A análise exige usuário autenticado, token CSRF e permissão de **editar** o projeto;
para comparar projetos, a permissão é conferida em ambos **antes** de qualquer
leitura dos arquivos. Visitantes continuam no modo somente leitura e não veem
a nova ferramenta. São respeitados os limites de leitura da arquitetura: até 850
registros, 256 KiB por arquivo e 12 MiB por projeto; cada analisador possui ainda
seus próprios limites. O endpoint apenas consulta dados e não grava nada.

Não foi adicionada nenhuma migração de banco de dados. Para atualizar, faça backup,
desative temporariamente no ACP sem apagar dados, substitua os arquivos em
`ext/mundophpbb/workspace/`, reative, limpe o cache do phpBB e atualize o navegador
com Ctrl+F5. Valide primeiro em uma instalação de testes.


## Workspace IDE 1.25.0 — Pré-visualização isolada de templates

Em **Ferramentas → Pré-visualizar templates** é possível escolher um arquivo
`.html`, `.htm` ou `.twig` do projeto, um CSS opcional e um perfil de exemplo
(visitante ou usuário registrado). Há opções para visualização desktop e
celular, e para incluir o conteúdo ainda não salvo do arquivo aberto no Ace.
Nenhum arquivo é modificado por esta funcionalidade.

A ferramenta gera uma **maquete visual ilustrativa**. Ela substitui variáveis
simples por exemplos, demonstra alguns blocos condicionais e repete loops com
dois itens fictícios. Não é o motor Twig real do phpBB: expressões, filtros,
includes, eventos e funções específicas podem não coincidir com o resultado
em produção. Erros no template devem ser confirmados no phpBB real.

**Isolamento:** a resposta autorizada é desenhada em `<iframe sandbox="">`
sem `allow-scripts` ou `allow-same-origin`, com origem opaca e uma Content
Security Policy que nega scripts, conexões, fontes externas, iframes,
formulários e recursos remotos. O navegador elimina elementos ativos,
links e atributos de evento antes de montar `srcdoc`. Mesmo que um arquivo
contenha HTML/JS hostil, ele não é executado. A prévia exige usuário
**autenticado**, token CSRF e permissão de edição no projeto; visitantes não
a visualizam nem podem chamar os endpoints. Apenas arquivos do projeto
selecionado são consultados. A listagem lê até 1200 registros; HTML até
128 KiB e CSS até 96 KiB por arquivo, sem executar templates no servidor.

Não há novas migrações. Para atualizar, faça backup, desative a extensão sem
excluir os dados, substitua os arquivos em `ext/mundophpbb/workspace/`,
reative e limpe o cache do phpBB e do navegador. Faça primeiro a homologação
em uma instalação de testes.


## Correção da versão 1.26.1

- Corrigido o delimitador Twig do carregamento de `wsp_documentation.js` em `styles/all/template/workspace_main.html`: `%>` foi substituído por `%}`.
- O delimitador incorreto causava o erro `Unexpected token "operator" of value ">"` ao compilar o template.
- Incluído teste automatizado para verificar se as tags `{% ... %}` estão corretamente fechadas nos templates da extensão.
- Sem alterações de dados, permissões ou migrações.


## Workspace IDE 1.28.0 — GitHub Actions e workflow phpBB

No menu **Ferramentas → GitHub e repositórios**, projetos vinculados a repositórios públicos podem:

- Consultar **manualmente** até oito execuções recentes do GitHub Actions (status, resultado, branch e link canônico para a execução). As consultas usam o limite de API compartilhado da 1.27.0; nenhuma consulta é agendada em segundo plano.
- Preparar o arquivo `.github/workflows/tests.yml` para o framework oficial `phpbb-extensions/test-framework`, com escolha entre verificações básicas e matriz completa, e phpBB 3.3.x ou a branch de desenvolvimento.
- Revisar, copiar ou baixar o YAML gerado. **Nenhum arquivo é gravado automaticamente no projeto ou no GitHub**; o desenvolvedor precisa colocar o `composer.json` na raiz do repositório e publicar o workflow manualmente.

A integração permanece **opcional**, usando apenas repositórios públicos, sem autenticação, tokens, commits, releases nem acesso de visitantes. Não são criadas novas tabelas nem migrações de banco. As ferramentas do Laboratório de Testes continuam funcionando independentemente do GitHub.


## Workspace IDE 1.29.0 — Releases públicas do GitHub

- Em **Ferramentas → GitHub e repositórios → Releases publicadas**, consulte até oito versões publicadas do repositório público vinculado ao projeto, com tag, título, data, notas resumidas e links oficiais. Pré-lançamentos são identificados; rascunhos não são exibidos. Tags sem GitHub Release não aparecem.
- Em cada Release compatível, selecione **Comparar com o projeto** para obter um resumo de arquivos novos, alterados, iguais e existentes apenas no Workspace. A referência da Release é resolvida para SHA no servidor e o ZIP é analisado com os limites e as verificações do importador existente.
- **Nenhum arquivo é importado, removido ou alterado pela comparação.** Não são enviados commits nem criadas Releases; nenhuma senha ou token GitHub é solicitado.
- Requer GitHub habilitado no ACP, vínculo de repositório público e permissão de edição no projeto. Usuários sem edição e visitantes não acessam os endpoints, que exigem POST e token CSRF.
- Limitações: a comparação usa apenas os **arquivos já salvos**, não mudanças abertas no editor; arquivos não permitidos pelo importador podem ser ignorados, e projetos grandes podem exceder os limites de ZIP, quantidade de arquivos ou tamanho. O resultado não é um diff de cada linha, e sim um resumo por arquivo.
- **Sem nova migração de banco** em 1.29.0. A migração GitHub v119 (introduzida em 1.27.0) continua necessária.

## GitHub comparison report (1.30.0)

Release comparisons now offer local filters for new, modified and project-only files, plus a downloadable UTF-8 CSV report with the comparison SHA, tag, totals and visible paths. No extra GitHub requests or server-side writes are needed. The CSV records whether the file list was truncated to 180 differences; totals always cover the entire analyzed comparison. Spreadsheet formula prefixes in all exported fields are escaped.

### Workspace 1.31.0 — Comparação visual linha a linha de Releases

Em Ferramentas → GitHub e repositórios, consulte Releases públicas, compare a Release com os arquivos salvos e clique em **Ver diferenças de linhas** em um arquivo divergente. A visualização apresenta números de linha antigos e novos, remoções do Workspace em vermelho e acréscimos do GitHub em verde. Cada comparação revalida a Release, a tag e o commit SHA no servidor; tags alteradas invalidam o relatório anterior. Somente arquivos de texto UTF-8 de até 64 KiB e 320 linhas por lado são comparáveis. A comparação exige permissão de edição, POST/CSRF, repositório público vinculado e integração habilitada no ACP. Nenhum conteúdo é gravado, removido, publicado ou sincronizado automaticamente.

### Historico GitHub (1.32.0)
Em Ferramentas > GitHub e repositorios > Historico de commits, o editor pode consultar os oito commits mais recentes da referencia vinculada, ver arquivos alterados (ate 100) e comparar um commit com os arquivos salvos. O SHA deve estar entre os commits recentes da referencia vinculada. A comparacao e somente leitura, usa o snapshot inteiro do commit e aplica os limites de arquivo da versao 1.31.0. Exige usuario autenticado, edicao, POST e CSRF. Sem tokens, publicacao ou novas migracoes.

## 1.34.0 — Release publication readiness checklist

The existing release assistant now includes a read-only publication checklist based on saved project files: composer identity and semantic version, README, changelog, changelog version reference, license, pt_br/en language files and optional GitHub Actions workflow presence. It adds advisory findings to the downloaded validation report. This checklist does not replace official EPV verification, inspect unsaved edits, attest external CI results or independently authorize ZIP downloads. The existing EPV-approved, fingerprint-guarded ZIP export remains unchanged.


## Workspace IDE 1.35.0 — Release history (project-local)

In **History → Release history**, an editor can record a version, the intended distribution ZIP filename and release notes.
A release record atomically preserves a content-addressed snapshot of the **saved** project files, using the existing project checkpoint machinery.
Each project can store up to 20 preserved release snapshots; these are excluded from ordinary rolling checkpoint pruning.
From a release record, **Preview recovery** opens the existing checkpoint preview; restoration still requires explicit confirmation and full edit/upload/delete/move privileges.

Important: this feature **does not archive the produced ZIP**, automatically validate a release, certify EPV, or publish anything to GitHub. The ZIP filename is descriptive metadata only; the SHA-256 shown is a canonical fingerprint of the saved file contents, **not** the hash of the distributed ZIP. Unsaved edits are not included.
A new phpBB database migration (`v120`) creates `workspace_release_records`. Back up both files and database before upgrading and never delete extension data during the upgrade.

## Workspace 1.38.0 — Project maintenance overview

The Projects and Releases hub now surfaces the numbers of open tasks (`todo` and `doing`), locked projects, and a non-blocking age reminder for releases recorded at least 90 days ago. A new filter shows only projects with open tasks or locks. This is strictly a read-only overview of saved records, not a live EPV, CI, GitHub publication, or security verdict. The existing authorization checks and 200-project limit remain in force.

## 1.41.1 — Correção do menu ACP

A migração `v121` registra a opção **Central de diagnóstico** sob **ACP → Extensões → Workspace IDE** em instalações já existentes. A configuração anterior permanece intacta. Desative sem excluir os dados, atualize os arquivos, reative e limpe o cache.

### Workspace IDE 1.43.0 — Backup and integrity audit

In **Tools → History → Projects and releases**, users who can edit a project can
choose **Check snapshot** to examine its most recent *manual* restore point.
The server validates each stored file's SHA-256 hash and the snapshot fingerprint,
then compares the verified snapshot with the saved project files. The result
indicates whether the saved files still match, have changed since capture, or
whether the snapshot is unavailable/corrupted. Snapshots older than 30 days
receive an informational review reminder. This audit never writes, restores,
or deletes data. It does not execute code or send data to external services.

Restore points are held **inside the phpBB database** and subject to the
existing checkpoint limits (350 files, 8 MiB aggregate). They are not a separate
backup of the database, the full forum, attachments, or server files. Keep
independent, restorable backups of the database and server files, particularly
before upgrading phpBB or Workspace. To create a manual restore point, open the
project's **History → Restore points** tool. The audit is opt-in, CSRF-protected
and permission-scoped to the selected project; no schema migration is added.

### Workspace 1.44.0 — backups ZIP portáteis (somente leitura na verificação)

Na Central de projetos e lançamentos, um editor autorizado pode exportar os arquivos salvos
em um ZIP independente do banco. O arquivo contém `manifest.json` (formato
`workspace-project-backup-v1`) com SHA-256 por arquivo e impressão digital do manifesto.
O limite é o mesmo dos pontos de restauração: 350 arquivos e 8 MiB de conteúdo,
com até 10 MiB para o ZIP. Requer `ZipArchive` no servidor.

A opção **Verificar ZIP** aceita um pacote local e mostra diferenças antes de qualquer
recuperação, sem extrair nem executar conteúdo. Nesta versão a importação é **somente
prévia**, não há botão de restauração do ZIP. Para evitar restaurar arquivos no projeto
errado, a verificação exige o mesmo `project_id` do manifesto; a migração entre
instalações com IDs diferentes ainda não é suportada. Recomenda-se também backup
externo completo do banco de dados, dos arquivos do phpBB e dos anexos.

### Composer editor proposal (1.49.0)

In **Tools > Test Laboratory**, run the project analysis and review the new
**composer.json adjustment preview** when available. Only identical duplicate
package constraints in `require` and `require-dev` are proposed for removal
from `require-dev`. The before/after panel shows the entire JSON, including
formatting changes. Open the saved `composer.json` in the editor before using
**Stage proposal in editor**. The stage operation checks that the saved source
has not changed and that there are no unsaved editor edits, asks for explicit
confirmation, then updates only the editor buffer. Review and click the
ordinary **Save** button to persist, subject to the Workspace's usual project
permissions, file locks and revision checks. Other Composer warnings remain
manual suggestions. No dependency installation or `composer update` occurs.


### Workspace 1.50.0 — Estabilidade e migrations

- ACP > Central de diagnóstico: resumo de estados e instruções recolhíveis, inclusive em telas menores.
- Mapa consolidado **somente para consulta** das 22 migrations históricas: `docs/MIGRATIONS_CONSOLIDADAS.md`.
- Auditor independente e sem conexão com o banco: `php tools/migration_inventory.php --markdown` ou `--json`. Valida a cadeia de dependências e lista as 16 tabelas declaradas.
- Para preservar atualizações e instalações existentes, **não foram substituídas, removidas nem renumeradas** as migrations v100–v121. Não há nova migração nesta versão.
- A consolidação executável de uma instalação limpa exigiria testes reais de atualização, instalação e desinstalação em bancos suportados pelo phpBB, portanto não está ativada aqui.

## 1.55.0 — Carregamento dinâmico dos módulos opcionais

A página inicial deixa de incluir 11 scripts de ferramentas opcionais. O `wsp_init.js`, carregado normalmente via phpBB, resolve o diretório dos seus próprios scripts (mesma origem), solicita o módulo somente no primeiro clique da ferramenta, inicializa seus eventos e então retoma a abertura. Chamadas repetidas durante o download não duplicam a inicialização; erros de rede exibem aviso e permitem nova tentativa no próximo clique. Se o projeto ativo mudar durante o carregamento, o clique antigo não é reproduzido.

Ferramentas adiadas: Gerador Visual, Arquitetura, Permissões, Laboratório, Auditor de Segurança, Assistente de Atualização, Painel de Qualidade, Prévia de Templates, Notas de Release, Documentação e GitHub. O editor, arquivos, projetos, menus, Radar e demais módulos essenciais seguem carregando como antes. Nenhum endpoint, dado, permissão ou migration foi alterado.

Essa etapa reduz o tamanho dos scripts solicitados no carregamento inicial em aproximadamente **178 KB não compactados** (antes de compressão HTTP e cache). Os scripts ainda são necessários quando suas respectivas ferramentas são abertas. Não há medição de tempo real de carregamento em phpBB; valide em homologação, especialmente com CSP/cache/proxy próprios da hospedagem. Para uma falha no primeiro carregamento, verifique o console de rede do navegador e se o arquivo está acessível em `styles/all/template/js/`.


### 1.56.0 — Leitura seletiva do histórico de atividades

As telas de histórico buscam somente identificadores, ação, caminho, usuário e data. O campo de metadados (`TEXT_UNI`) não é consultado na lista comum, mas é preservado no banco e continua incluído quando o usuário exporta CSV. Nenhum índice, migration, endpoint ou política de permissão foi alterado. Esta otimização reduz a transferência de dados potencialmente grandes; ganhos de latência precisam ser aferidos no banco real.

## 1.57.0 — Monitor de desempenho (ACP)

Em ACP > Extensões > Workspace IDE > Monitor de desempenho, o administrador pode iniciar uma medição pontual de três consultas de contagem nas tabelas do Workspace. São apresentados duração individual e total, número das consultas e variação de memória. Nenhum dado de usuários, SQL com parâmetros, caminhos ou credenciais é exibido ou armazenado. Não existe coleta contínua. O modo requer a nova migration v122. A ferramenta não mede o desempenho total do fórum.

### v1.58.0 — One-time performance comparison

ACP performance diagnostics can optionally execute two consecutive bounded query-count samples and compare timings. It flags a query duration of 100 ms or above as an informational review threshold (not proof of a performance issue). Results are never persisted. The existing three-query snapshot remains available; comparison executes six queries.

### 1.58.2 — Botões discretos e barra mais espaçada

- Remove animações, elevação, filtros e sombras decorativas dos botões na interface Workspace.
- Aumenta o espaçamento e a área de clique dos ícones na barra principal, com ajustes responsivos.
- Preserva foco visível, estados ativos/desativados, eventos e permissões. Sem migrações.
