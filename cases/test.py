from vllm_wrapper import vLLMWrapper

model = vLLMWrapper('Qwen/Qwen-72B-Chat', tensor_parallel_size=2)

import sys

response, history = model.chat(query="你好", history=None)
print(response)
response, history = model.chat(query="给我讲一个年轻人奋斗创业最终取得成功的故事。", history=history)
print(response)
response, history = model.chat(query="给这个故事起一个标题", history=history)
print(response)


def test(name, n, hello, **kwargs):
    model2 = vLLMWrapper('Qwen/Qwen-72B-Chat', tensor_parallel_size=2)

    def it(m):

        return "hello world"

    s = it(model2)

    return model2

