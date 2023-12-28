import ast
import json
from ast2json import ast2json
import sys

if __name__ == "__main__":
    file_name = sys.argv[1]
    tree = ast2json(ast.parse(open(file_name).read()))
    print(json.dumps(tree, indent=4))
