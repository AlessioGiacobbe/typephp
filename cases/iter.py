from pycparser import CParser, parse_file, c_ast

# 解析 C 代码并打印函数声明及定义
def extract_functions_from_file(filename):
    # 解析 C 文件
    ast = parse_file(filename, use_cpp=True)

    # 用于存储函数声明和实现
    function_declarations = []
    function_definitions = []

    # 遍历 AST
    for node in ast.ext:
        # 检查节点类型
        if isinstance(node, c_ast.FuncDef):
            # 如果是函数定义，保存定义
            function_definitions.append(node.decl.name)
        elif isinstance(node, c_ast.Decl):
            # 如果是函数声明
            if isinstance(node.type, c_ast.FuncType):
                function_declarations.append(node.name)

    # 输出结果
    print("Function Declarations:")
    for decl in function_declarations:
        print(decl)

    print("\nFunction Definitions:")
    for defi in function_definitions:
        print(defi)

# 调用函数，解析指定文件
extract_functions_from_file('your_file.c')  # 替换为你的 C 源文件
