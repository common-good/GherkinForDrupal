@REM %2 is start, publish, or finish
git flow feature %2 %1

IF "%2"=="finish" git push
