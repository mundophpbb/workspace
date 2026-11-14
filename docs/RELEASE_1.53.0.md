# Workspace IDE 1.53.0 - Navegacao e estabilidade de carregamento

- Campo de pesquisa no menu Ferramentas, com busca instantanea e tolerancia a acentos, sem consultar o servidor.
- Categorias do menu sao ocultadas durante a pesquisa para reduzir o deslocamento vertical; com a busca vazia, permanece a organizacao anterior.
- Estado de busca e reiniciado quando o menu e reaberto; Enter executa a acao somente quando existe um unico resultado habilitado.
- Reposicionamento dos menus durante scroll/resize e limitado a uma atualizacao por frame visual (requestAnimationFrame, com fallback).
- Nao altera IDs de botoes, eventos antigos, rotas, permissoes, estrutura de banco de dados nem migrations.
- Nota: esta versao nao implementa carregamento sob demanda de modulos JavaScript nem otimiza consultas SQL. Sem benchmarks no phpBB real nao e possivel afirmar ganhos quantitativos de velocidade.

Atualizacao: backup dos arquivos e banco; desativar no ACP sem excluir dados; substituir arquivos da extensao; reativar; limpar cache phpBB e navegador.
