#!/usr/bin/env python3

# Sanitize the gitchangelog output

import shutil

inputFile = "../docs/Changelog.md"
outputFile = "/tmp/Changelog.tmp"

previousLine = ""

with open(outputFile, "w") as output_file:
    for line in open(inputFile, "r"):
        if line != previousLine:
            output_file.write(line)
        previousLine = line
shutil.move(outputFile, inputFile)
