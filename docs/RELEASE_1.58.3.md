# Workspace IDE 1.58.3 — Compatibilidade e validação

Correção conservadora de falsos positivos identificados no relatório de Validação Completa da versão 1.58.2 (13 erros, 11 avisos).

- A identificação de parâmetros de construtor utiliza `token_get_all()` para ignorar modelos PHP contidos em strings/nowdocs do Gerador Visual.
- A detecção de acesso a superglobais examina apenas tokens `T_VARIABLE` e não confunde expressões regulares, comentários ou exemplos com leituras reais. Ferramentas CLI e fixtures de teste não entram nessa regra de uso da Request API.
- A verificação de chaves de idioma ignora dados ilustrativos de testes e scripts CLI. O detector de mensagens hard-coded não confunde o inventário técnico de migrations com interface pública.
- A prévia de template utiliza `utf8_htmlspecialchars()` do phpBB. Os testes isolados incluem shim restrito ao ambiente de testes.
- O modelo de listener gerado deixa de emitir um marcador TODO de exemplo.
- Dois pacotes: fonte completo e distribuição sem `tests/` e `tools/`, para impedir submissão de fixtures com classes globais e códigos inseguros intencionais.

## Restrições e pendências

O relatório anterior também indicou 106 arquivos com indentação por espaços, dois arquivos com comentários possivelmente fora do inglês e cinco alertas SQL heurísticos. **Esses itens não foram normalizados indiscriminadamente nem declarados resolvidos**: exigem análise individual e execução do EPV oficial. O servidor relatou `proc_open()` desativado; portanto, a validação completa oficial não foi executada neste ambiente. O ZIP de distribuição é um **candidato à submissão**, não aprovação garantida.

Sem alterações de migrations, esquema, permissões, rotas ou layout.
