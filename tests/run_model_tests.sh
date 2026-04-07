#!/bin/bash

DIR="/home/iceman/Desktop/cf-llm-test"
TRANSLATE="php /home/iceman/Documents/projects/Claude/ai.opensubtitles.com/cf-llm-srt-translator/translate.php"
LONG_FILE="$DIR/The.Matrix.1999.Tubi.CC.en.srt"
LOG_FILE="$DIR/test_results.log"

MODELS="gpt-oss-120b llama-4-scout gemma-3-12b mistral-small-3.1 sea-lion-27b"
REASONING_MODELS="gpt-oss-120b"

echo "========================================" > "$LOG_FILE"
echo "MODEL TEST RESULTS" >> "$LOG_FILE"
echo "Date: $(date)" >> "$LOG_FILE"
echo "File: The.Matrix.1999.Tubi.CC.en.srt" >> "$LOG_FILE"
echo "Language: German" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"

for model in $MODELS; do
    for format in json simple; do
        for think in no yes; do
            # Skip reasoning options for non-reasoning models
            if [ "$think" = "yes" ]; then
                is_reasoning=false
                for rm in $REASONING_MODELS; do
                    if [ "$model" = "$rm" ]; then
                        is_reasoning=true
                    fi
                done
                if [ "$is_reasoning" = false ]; then
                    continue
                fi
            fi
            
            think_flag=""
            think_str="nothink"
            if [ "$think" = "yes" ]; then
                think_flag="-r"
                think_str="think"
            fi
            
            output="$DIR/${model}_${format}_${think_str}_matrix.srt"
            rm -f "${LONG_FILE}.progress"
            rm -f "${LONG_FILE}.${model}.debug.txt"
            
            echo "----------------------------------------" >> "$LOG_FILE"
            echo "MODEL: $model | FORMAT: $format | REASONING: $think_str" >> "$LOG_FILE"
            echo "START: $(date)" >> "$LOG_FILE"
            echo "----------------------------------------" >> "$LOG_FILE"
            
            $TRANSLATE -i "$LONG_FILE" -l German -m "$model" -f "$format" -o "$output" $think_flag 2>&1 | tee -a "$LOG_FILE"
            EXIT_CODE=${PIPESTATUS[0]}
            
            if [ $EXIT_CODE -eq 0 ]; then
                echo "RESULT: SUCCESS" >> "$LOG_FILE"
            else
                echo "RESULT: FAILED (exit code: $EXIT_CODE)" >> "$LOG_FILE"
            fi
            
            echo "" >> "$LOG_FILE"
        done
    done
done

echo "========================================" >> "$LOG_FILE"
echo "ALL TESTS COMPLETE: $(date)" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"

cat "$LOG_FILE"
