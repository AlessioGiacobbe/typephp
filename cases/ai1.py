from modelscope.pipelines import pipeline
from modelscope.outputs import OutputKeys
from PIL import Image
from background_generation import modelscope_warpper

model = "damo/cv_background_generation_sd"
pipe = pipeline('background_generation_task', model=model, device='gpu',auto_collate=False,model_revision='v1.1.0')
main_image='https://vision-poster.oss-cn-shanghai.aliyuncs.com/lllcho.lc/data/test_data/demo_example/%E5%8C%96%E5%A6%86%E5%93%81/1c33fc5e8b084269ffdb4e0557c2c3c4.png'
reference_image='https://vision-poster.oss-cn-shanghai.aliyuncs.com/lllcho.lc/data/test_data/5d873b5f64b82bcbb235748347602dce38c6ec1d.jpg'
out=pipe(main_image,reference_image,num_images_per_prompt=1)
imgs=out[OutputKeys.OUTPUT_IMGS]
imgs[0].save(f'result.jpg')
