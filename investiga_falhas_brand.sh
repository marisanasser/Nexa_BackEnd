#!/usr/bin/env bash
set -euo pipefail
shopt -s extglob

DAYS=6  # últimos 7 dias (0..6)
DATES="$(for i in $(seq 0 $DAYS); do date -d "$i days ago" +%F; done | paste -sd'|')"

ATT=/tmp/brand_attempts.tsv
SUC=/tmp/brand_success.tsv
OUT=/tmp/brand_fail_report.txt
: > "$ATT"; : > "$SUC"; : > "$OUT"

# 1) Coleta tentativas (brand) com arquivo, linha, timestamp e email
grep -nH -i 'Registration request received' storage/logs/laravel*.log \
| grep -E "$DATES" \
| grep -i '"role":"brand"' \
| awk -F: '{
  file=$1; line=$2; rest=substr($0, index($0,$3));
  ts=""; email="";
  if (match(rest, /\[([0-9-]+\s+[0-9:]+)/, m)) ts=m[1];
  if (match(rest, /"email":"([^"]+)"/, e)) email=e[1];
  printf "%s\t%s\t%s\t%s\n", file, line, ts, tolower(email);
}' > "$ATT"

# 2) Coleta sucessos com arquivo, linha, timestamp e email
grep -nH -i 'User registration completed successfully' storage/logs/laravel*.log \
| grep -E "$DATES" \
| awk -F: '{
  file=$1; line=$2; rest=substr($0, index($0,$3));
  ts=""; email="";
  if (match(rest, /\[([0-9-]+\s+[0-9:]+)/, m)) ts=m[1];
  if (match(rest, /"email":"([^"]+)"/, e)) email=e[1];
  printf "%s\t%s\t%s\t%s\n", file, line, ts, tolower(email);
}' > "$SUC"

# 3) Indexa sucessos por data (YYYY-MM-DD) + email
awk -F'\t' '
function ymd(s){ return substr(s,1,10) }
{ key=ymd($3) "|" $4; if(!(key in minLine) || $2+0 < minLine[key]+0){ minLine[key]=$2+0; file[key]=$1 } }
END{ for(k in minLine) print k "\t" file[k] "\t" minLine[k] }
' "$SUC" | sort > /tmp/success_index.tsv

# 4) Para cada tentativa, se não houver sucesso do mesmo email no mesmo dia OU o sucesso vier antes da tentativa, marca como falha
echo "==== FALHAS (últimos 7 dias) ====" >> "$OUT"
echo "" >> "$OUT"

while IFS=$'\t' read -r file line ts email; do
  day="${ts%% *}"
  key="$day|$email"
  suc_file="$(awk -F'\t' -v k="$key" '$1==k{print $2}' /tmp/success_index.tsv | head -n1)"
  suc_line="$(awk -F'\t' -v k="$key" '$1==k{print $3}' /tmp/success_index.tsv | head -n1)"

  fail=0
  if [[ -z "$suc_file" || -z "$suc_line" ]]; then
    fail=1
  else
    # Se houve sucesso no mesmo dia, mas em linha anterior, considera que esta tentativa falhou e outra posterior pode ter dado certo
    if (( suc_line + 0 < line + 0 )); then
      # há sucesso depois? (procurar outra linha de sucesso com linha > tentativa)
      next_suc_line="$(awk -F'\t' -v k="$key" -v ln="$line" '($1==k)&&($3+0>ln+0){print $3}' /tmp/success_index.tsv | sort -n | head -n1)"
      if [[ -z "$next_suc_line" ]]; then
        fail=1
      fi
    fi
  fi

  if (( fail == 1 )); then
    echo "----" >> "$OUT"
    echo "Tentativa: $ts | email=$email" >> "$OUT"
    echo "Arquivo: $file (linha $line)" >> "$OUT"
    echo "Motivos próximos (erros/avisos no entorno):" >> "$OUT"
    start=$(( line-50 )); (( start < 1 )) && start=1
    end=$(( line+250 ))
    sed -n "${start},${end}p" "$file" \
    | grep -nE 'ERROR|WARNING|Exception|SQLSTATE|QueryException|duplicate|unique|validation|Too Many|rate limit|maintenance|permission|denied' \
    | head -n 15 >> "$OUT"
    echo "" >> "$OUT"
  fi
done < "$ATT"

# 5) Sumário por email (tentativas vs sucessos vs falhas)
echo "" >> "$OUT"
echo "==== RESUMO POR EMAIL (últimos 7 dias) ====" >> "$OUT"
awk -F'\t' '{print tolower($4)}' "$ATT" | sort | uniq -c | awk '{print $2 "\t" $1}' | sort > /tmp/att_counts.tsv
awk -F'\t' '{print tolower($4)}' "$SUC" | sort | uniq -c | awk '{print $2 "\t" $1}' | sort > /tmp/suc_counts.tsv
join -a1 -a2 -e 0 -t $'\t' -o 1.1,1.2,2.2 /tmp/att_counts.tsv /tmp/suc_counts.tsv \
| awk -F'\t' 'BEGIN{printf "%-40s %8s %8s %8s\n","email","attempts","success","failed"} {printf "%-40s %8d %8d %8d\n",$1,$2,$3,$2-$3}' \
| sort -k2,2nr >> "$OUT"

echo "Relatório gerado em: $OUT"
